<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Commands;

use Henryavila\Codeguard\Install\AllowPluginsStatus;
use Henryavila\Codeguard\Install\CaptainhookInstaller;
use Henryavila\Codeguard\Install\CaptainhookInstallResult;
use Henryavila\Codeguard\Install\CaptainhookInstallStatus;
use Henryavila\Codeguard\Install\CodeguardDirectoryInitializer;
use Henryavila\Codeguard\Install\ComposerAllowPluginsCheck;
use Henryavila\Codeguard\Install\DeptracLayerSuggester;
use Henryavila\Codeguard\Install\DeptracLayerWizard;
use Henryavila\Codeguard\Install\EnvironmentDetector;
use Henryavila\Codeguard\Install\EnvironmentInfo;
use Henryavila\Codeguard\Install\GatePlan;
use Henryavila\Codeguard\Install\GatePlanRegistry;
use Henryavila\Codeguard\Install\InstallSummary;
use Henryavila\Codeguard\Install\InstallTelemetry;
use Henryavila\Codeguard\Install\InstallWarning;
use Henryavila\Codeguard\Install\LayerDecisionStore;
use Henryavila\Codeguard\Install\LayerOption;
use Henryavila\Codeguard\Install\LayerSuggestion;
use Henryavila\Codeguard\Install\NextStepsReporter;
use Henryavila\Codeguard\Install\PhpstanExtension;
use Henryavila\Codeguard\Install\PhpstanExtensionApplier;
use Henryavila\Codeguard\Install\PhpstanExtensionSelector;
use Henryavila\Codeguard\Install\PhpstanExtensionStore;
use Henryavila\Codeguard\Install\PresetSelector;
use Henryavila\Codeguard\Install\StubPublisher;
use Henryavila\Codeguard\Install\StubPublishResult;
use Henryavila\Codeguard\Install\StubPublishStatus;
use Henryavila\Codeguard\Install\StubRegistry;
use Henryavila\Codeguard\Install\WarningCode;
use Henryavila\Codeguard\Install\WarningLevel;
use Henryavila\Codeguard\Testing\Preset;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Formatter\OutputFormatter;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

final class CodeguardInstallCommand extends Command
{
    protected $signature = 'codeguard:install
        {--preset= : Force preset (default|full). Skip auto-detection.}
        {--no-interactive : CI mode — use auto-detection, no prompts.}
        {--refresh-stubs : Overwrite existing stubs after diff review.}
        {--no-deptrac-wizard : Skip the guided layer-classification wizard (use heuristic only).}';

    protected $description = 'Guided install — detects environment, selects preset, publishes stubs with diff-aware re-run, suggests Deptrac layers, verifies CaptainHook, prints next-steps.';

    public function handle(
        EnvironmentDetector $detector,
        PresetSelector $presetSelector,
        StubRegistry $registry,
        GatePlanRegistry $planRegistry,
        StubPublisher $publisher,
        DeptracLayerSuggester $deptracSuggester,
        DeptracLayerWizard $deptracWizard,
        LayerDecisionStore $layerDecisionStore,
        CaptainhookInstaller $captainhookInstaller,
        PhpstanExtensionSelector $phpstanExtSelector,
        PhpstanExtensionStore $phpstanExtStore,
        PhpstanExtensionApplier $phpstanExtApplier,
        NextStepsReporter $reporter,
        Filesystem $filesystem,
        ComposerAllowPluginsCheck $allowPluginsCheck,
        CodeguardDirectoryInitializer $directoryInitializer,
        InstallTelemetry $telemetry,
    ): int {
        $interactive = ! $this->option('no-interactive');
        $forceOverwrite = (bool) $this->option('refresh-stubs');
        $skipWizard = (bool) $this->option('no-deptrac-wizard');
        $presetFlag = $this->option('preset');

        $startHrtime = hrtime(true);
        $presetFlagStr = is_string($presetFlag) ? $presetFlag : null;
        $telemetry->commandStarted($presetFlagStr);

        $summary = new InstallSummary;

        $this->renderHeader();

        $environment = $detector->detect();
        $telemetry->envDetected($environment);
        $this->renderEnvironment($environment);
        $this->checkPhpVersion($environment, $summary);

        $recommended = $presetSelector->autoSelect($environment);
        $this->renderRecommendation($recommended, $environment);

        $preset = $this->resolvePreset($environment, $presetSelector, $presetFlag, $interactive);
        $presetSource = match (true) {
            is_string($presetFlag) && $presetFlag !== '' => 'flag',
            ! $interactive => 'auto',
            default => 'prompt',
        };
        $telemetry->presetSelected($preset, $presetSource);
        $this->line('');
        $this->components->twoColumnDetail('Selected preset', $preset->label());

        if ($preset->requiresNode() && ! $environment->hasNode()) {
            $summary->warn(new InstallWarning(
                level: WarningLevel::Warning,
                code: WarningCode::NodeMissingForFullPreset,
                message: 'Preset codeguard-full requires Node.js but none was detected.',
                remediation: 'Install Node.js 18+ (jscpd + Insights depend on it) or switch to --preset=default.',
            ));
        }

        $this->ensureCaptainhookPluginAllowed($allowPluginsCheck, $summary, $interactive);

        $selectedExtensions = $this->selectPhpstanExtensions(
            phpstanExtSelector: $phpstanExtSelector,
            phpstanExtStore: $phpstanExtStore,
            interactive: $interactive,
        );
        $telemetry->phpstanExtensionsSelected($selectedExtensions);

        $plans = $planRegistry->plansFor($preset);
        $this->renderGatePlan($plans, $planRegistry, $selectedExtensions);

        if (! $this->confirmProceed($interactive)) {
            $this->components->warn('Install aborted.');
            $telemetry->commandEnded(self::SUCCESS, $this->elapsedMsFrom($startHrtime));

            return self::SUCCESS;
        }

        // Ensure .codeguard/.gitignore exists BEFORE any stub publish or
        // Phase B telemetry writer lands a file in the directory — this
        // prevents telemetry.jsonl / baseline.json from leaking into git
        // on fresh installs.
        $directoryInitializer->initialize();

        $this->line('');
        $this->components->info('Publishing stubs...');

        $publisher->useOutput($this->output);

        $stubs = $registry->stubsFor($preset);
        $results = $publisher->publish($stubs, $forceOverwrite, $interactive);

        $this->renderStubResults($results);

        if ($this->hasFailures($results)) {
            $this->components->error('One or more stubs failed to publish. See messages above.');
            $telemetry->commandEnded(self::FAILURE, $this->elapsedMsFrom($startHrtime));

            return self::FAILURE;
        }

        $this->applyPhpstanExtensionsToStub(
            selectedExtensions: $selectedExtensions,
            phpstanExtStore: $phpstanExtStore,
            phpstanExtApplier: $phpstanExtApplier,
        );

        $this->maybeSuggestDeptracLayers(
            $preset,
            $deptracSuggester,
            $deptracWizard,
            $layerDecisionStore,
            $filesystem,
            $interactive,
            $skipWizard,
        );
        $captainhookResult = $this->maybeInstallCaptainhook($preset, $environment, $captainhookInstaller, $summary);
        $telemetry->captainhookActivated($captainhookResult);

        $this->renderSummary($summary);

        $this->line('');
        $this->components->info('Next steps:');
        $nextStepsCount = $this->renderNextSteps($preset, $reporter);
        $telemetry->nextStepsRendered($nextStepsCount);

        $this->line('');
        $this->components->twoColumnDetail('Docs', $reporter->documentationUrl());

        $exitCode = $this->resolveExitCode($summary);
        $telemetry->commandEnded($exitCode, $this->elapsedMsFrom($startHrtime));

        return $exitCode;
    }

    private function elapsedMsFrom(int $startHrtime): int
    {
        return (int) round((hrtime(true) - $startHrtime) / 1_000_000);
    }

    private function resolveExitCode(InstallSummary $summary): int
    {
        if (! $summary->hasIssues()) {
            return self::SUCCESS;
        }

        // Exit 2 (not SUCCESS=0, not FAILURE=1) so scripts can tell
        // "setup incomplete but non-fatal" apart from hard errors like
        // stub publish failures which already return FAILURE above.
        return 2;
    }

    private function ensureCaptainhookPluginAllowed(
        ComposerAllowPluginsCheck $check,
        InstallSummary $summary,
        bool $interactive,
    ): void {
        $plugin = 'captainhook/hook-installer';
        $status = $check->check($plugin);

        if ($status === AllowPluginsStatus::Allowed || $status === AllowPluginsStatus::Unknown) {
            return;
        }

        if ($interactive) {
            $this->line('');
            $this->components->warn(
                "Composer plugin {$plugin} is blocked in composer.json — CaptainHook cannot auto-wire hooks until it's allowed.",
            );

            $approved = confirm(
                label: "Add {$plugin} to config.allow-plugins now?",
                default: true,
                hint: 'Writes a single key to composer.json; reversible by hand.',
            );

            if ($approved && $check->allow($plugin)) {
                $this->components->twoColumnDetail(
                    "composer.json → config.allow-plugins.{$plugin}",
                    '<fg=green>true (auto-added)</>',
                );

                return;
            }
        }

        $summary->warn(new InstallWarning(
            level: WarningLevel::Warning,
            code: WarningCode::CaptainhookPluginBlocked,
            message: "Composer plugin {$plugin} is not listed in config.allow-plugins — hooks will not auto-install.",
            remediation: "Run: composer config allow-plugins.{$plugin} true",
        ));
    }

    private function checkPhpVersion(EnvironmentInfo $env, InstallSummary $summary): void
    {
        if (version_compare($env->phpVersion, '8.3.0', '>=')) {
            return;
        }

        $summary->warn(new InstallWarning(
            level: WarningLevel::Error,
            code: WarningCode::PhpVersionTooLow,
            message: "PHP {$env->phpVersion} detected — CodeGuard requires 8.3 or newer.",
            remediation: 'Upgrade PHP to 8.3+ (some gates — readonly classes, match expressions — will fail at runtime otherwise).',
        ));
    }

    private function renderSummary(InstallSummary $summary): void
    {
        if ($summary->isEmpty()) {
            return;
        }

        $this->line('');
        $this->components->info('Install summary — pendencies to address');

        foreach ($summary->warnings() as $warning) {
            $label = match ($warning->level) {
                WarningLevel::Error => '<fg=red>✘ error</>',
                WarningLevel::Warning => '<fg=yellow>⚠ warning</>',
            };

            // Messages and remediations may one day come from disk (file
            // paths, plugin names, composer.json keys). Escape Symfony
            // Console markup so a stray `</>` from hostile or mis-encoded
            // input cannot corrupt the output pane.
            $message = OutputFormatter::escape($warning->message);
            $remediation = OutputFormatter::escape($warning->remediation);

            $this->components->twoColumnDetail(
                "  {$label}  <fg=gray>{$warning->code->value}</>",
                $message,
            );
            $this->line('    <fg=cyan>→</> '.$remediation);
        }
    }

    private function renderHeader(): void
    {
        $this->line('');
        $this->components->info('CodeGuard — Laravel quality gates installer');
        $this->line('');
    }

    private function renderEnvironment(EnvironmentInfo $env): void
    {
        $this->components->info('Detecting environment...');
        $this->components->twoColumnDetail('PHP', $env->phpVersion);
        $this->components->twoColumnDetail('Composer', $env->composerVersion);
        $this->components->twoColumnDetail(
            'Node.js',
            $env->nodeVersion ?? '<fg=gray>not detected</>',
        );
        $this->components->twoColumnDetail(
            'package.json',
            $env->hasPackageJson ? 'found' : '<fg=gray>not found</>',
        );
        $this->components->twoColumnDetail(
            'CaptainHook binary',
            $env->hasCaptainhookBinary ? 'available' : '<fg=gray>not installed</>',
        );
    }

    private function renderRecommendation(Preset $recommended, EnvironmentInfo $env): void
    {
        $this->line('');
        $this->components->twoColumnDetail(
            'Recommended preset',
            "<fg=yellow>{$recommended->label()}</> ⭐",
        );

        $reason = match ($env->nodeConfidence()) {
            'high' => 'Project uses Node.js (package.json or node_modules detected).',
            'medium' => 'Node.js available globally but this project does not use it.',
            default => 'No Node.js detected — PHP-native preset is the only supported option.',
        };

        $this->components->bulletList([$reason]);
    }

    private function resolvePreset(
        EnvironmentInfo $env,
        PresetSelector $selector,
        mixed $flag,
        bool $interactive,
    ): Preset {
        if (is_string($flag) && $flag !== '') {
            return $selector->resolveFromFlag($flag);
        }

        if (! $interactive) {
            return $selector->autoSelect($env);
        }

        return $selector->promptWithDefault($env);
    }

    /**
     * @param  list<GatePlan>  $plans
     * @param  list<PhpstanExtension>  $selectedExtensions
     */
    private function renderGatePlan(array $plans, GatePlanRegistry $registry, array $selectedExtensions = []): void
    {
        $this->line('');
        $this->components->info('=== Gates to install ===');

        $nameWidth = 0;
        $descWidth = 0;

        foreach ($plans as $plan) {
            $nameWidth = max($nameWidth, strlen($plan->gateName));
            $descWidth = max($descWidth, strlen($plan->description));
        }

        foreach ($plans as $plan) {
            $line = sprintf(
                '  <fg=green>✓</> %s  %s  <fg=gray>config:</> %-8s  <fg=gray>CI:</> %s',
                str_pad($plan->gateName, $nameWidth),
                str_pad($plan->description, $descWidth),
                $plan->configTimeLabel(),
                $plan->ciCostLabel(),
            );

            $this->line($line);
        }

        if ($selectedExtensions !== []) {
            $extensionNames = array_map(
                static fn (PhpstanExtension $ext): string => $ext->displayName(),
                $selectedExtensions,
            );

            $this->line('');
            $this->components->twoColumnDetail(
                'PHPStan extensions active',
                '<fg=cyan>'.implode(', ', $extensionNames).'</>',
            );
        }

        $total = $registry->totalConfigMinutes($plans);
        $this->line('');
        $this->components->twoColumnDetail(
            'Estimated total config',
            "<fg=yellow>{$registry->formatMinutes($total)}</>",
        );
    }

    private function confirmProceed(bool $interactive): bool
    {
        if (! $interactive) {
            return true;
        }

        return $this->confirm('Proceed with install?', default: true);
    }

    /**
     * @param  list<StubPublishResult>  $results
     */
    private function renderStubResults(array $results): void
    {
        foreach ($results as $result) {
            $label = $result->stub->targetRelativePath;
            $status = match ($result->status) {
                StubPublishStatus::Created => '<fg=green>created</>',
                StubPublishStatus::Unchanged => '<fg=gray>unchanged</>',
                StubPublishStatus::KeptCustom => '<fg=cyan>kept custom</>',
                StubPublishStatus::Overwritten => '<fg=yellow>overwritten</>',
                StubPublishStatus::Failed => '<fg=red>failed</>',
            };

            $this->components->twoColumnDetail($label, $status);

            if ($result->message !== null) {
                $this->components->bulletList([$result->message]);
            }

            if ($result->diff !== null && $this->output->isVerbose()) {
                $this->line('');
                $this->line($result->diff);
                $this->line('');
            }
        }
    }

    /**
     * @param  list<StubPublishResult>  $results
     */
    private function hasFailures(array $results): bool
    {
        foreach ($results as $result) {
            if (! $result->isSuccess()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<PhpstanExtension>
     */
    private function selectPhpstanExtensions(
        PhpstanExtensionSelector $phpstanExtSelector,
        PhpstanExtensionStore $phpstanExtStore,
        bool $interactive,
    ): array {
        $this->line('');
        $this->components->info('PHPStan extensions — which to activate in phpstan.neon');

        $saved = $phpstanExtStore->load();

        return $interactive
            ? $phpstanExtSelector->prompt($saved === [] ? PhpstanExtension::defaultEnabled() : $saved)
            : $phpstanExtSelector->autoResolve($saved);
    }

    /**
     * @param  list<PhpstanExtension>  $selectedExtensions
     */
    private function applyPhpstanExtensionsToStub(
        array $selectedExtensions,
        PhpstanExtensionStore $phpstanExtStore,
        PhpstanExtensionApplier $phpstanExtApplier,
    ): void {
        $phpstanPath = $this->laravel->basePath('phpstan.neon');

        if (! file_exists($phpstanPath)) {
            return;
        }

        $phpstanExtApplier->apply($phpstanPath, $selectedExtensions);
        $phpstanExtStore->save($selectedExtensions);

        $activeNames = array_map(
            static fn (PhpstanExtension $ext): string => $ext->displayName(),
            $selectedExtensions,
        );

        $this->line('');
        $this->components->twoColumnDetail(
            'phpstan.neon extensions active',
            '<fg=green>'.implode(', ', $activeNames).'</>',
        );
    }

    private function maybeSuggestDeptracLayers(
        Preset $preset,
        DeptracLayerSuggester $suggester,
        DeptracLayerWizard $wizard,
        LayerDecisionStore $decisionStore,
        Filesystem $filesystem,
        bool $interactive,
        bool $skipWizard,
    ): void {
        unset($preset);

        $appPath = $this->laravel->basePath('app');
        $suggestion = $suggester->suggest($appPath);

        if ($suggestion->isEmpty()) {
            return;
        }

        $this->line('');
        $this->components->info('Deptrac layer detection');
        $this->renderLayerSuggestion($suggestion);

        [$finalSuggestion, $wizardAction] = $this->runDeptracWizard(
            suggestion: $suggestion,
            suggester: $suggester,
            wizard: $wizard,
            decisionStore: $decisionStore,
            interactive: $interactive,
            skipWizard: $skipWizard,
        );

        $action = $wizardAction ?? $this->resolveDeptracAction($interactive, $skipWizard);

        if ($action === 'skip') {
            $this->components->bulletList(['Skipped — stub deptrac.yaml kept as-is.']);

            return;
        }

        $deptracPath = $this->laravel->basePath('deptrac.yaml');
        $yaml = $suggester->toYaml($finalSuggestion);

        try {
            $filesystem->put($deptracPath, $yaml);
        } catch (\Throwable $exception) {
            $this->components->error("Failed to write deptrac.yaml: {$exception->getMessage()}");

            return;
        }

        $this->components->twoColumnDetail(
            'deptrac.yaml',
            '<fg=green>written with suggested layers</>',
        );

        if ($action === 'edit') {
            $this->openInEditor($deptracPath);
        }
    }

    /**
     * @return array{0: LayerSuggestion, 1: ?string}
     */
    private function runDeptracWizard(
        LayerSuggestion $suggestion,
        DeptracLayerSuggester $suggester,
        DeptracLayerWizard $wizard,
        LayerDecisionStore $decisionStore,
        bool $interactive,
        bool $skipWizard,
    ): array {
        if (! $interactive || $skipWizard) {
            return [$suggestion, null];
        }

        $unclassifiedCount = 0;
        foreach ($suggestion->detectedNamespaces as $namespace) {
            if ($namespace->suggestedLayer === null) {
                $unclassifiedCount++;
            }
        }

        if ($unclassifiedCount === 0) {
            return [$suggestion, null];
        }

        $saved = $decisionStore->load();

        while (true) {
            $result = $wizard->classify($suggestion, $saved, $this->output);

            if ($result->isEmpty()) {
                return [$suggestion, null];
            }

            $reviewChoice = $wizard->review($suggestion, $result->decisions, $result->customLayers);

            if ($reviewChoice === 'restart') {
                $saved = [];

                continue;
            }

            $decisionStore->save($result);

            $enriched = $suggester->withDecisions($suggestion, $result);
            $action = $reviewChoice === 'edit' ? 'edit' : 'use';

            return [$enriched, $action];
        }
    }

    private function resolveDeptracAction(bool $interactive, bool $skipWizard): string
    {
        if (! $interactive) {
            return 'use';
        }

        if ($skipWizard) {
            return $this->promptDeptracDecision();
        }

        return 'use';
    }

    private function renderLayerSuggestion(LayerSuggestion $suggestion): void
    {
        foreach ($suggestion->detectedNamespaces as $namespace) {
            $layer = match ($namespace->suggestedLayer) {
                null => '<fg=gray>(unclassified — wizard will ask)</>',
                LayerOption::Skip->value => '<fg=yellow>Skip (auto — cross-cutting)</>',
                default => '<fg=cyan>'.$namespace->suggestedLayer.'</>',
            };
            $this->line(sprintf(
                '  %s  <fg=gray>(%d files)</>  → %s',
                $namespace->relativePath,
                $namespace->fileCount,
                $layer,
            ));
        }

        $this->line('');
        $this->line('  <fg=yellow>Layers:</>');
        foreach ($suggestion->layerNames() as $name) {
            $this->line("    - {$name}");
        }

        $this->line('  <fg=yellow>Rules:</>');
        foreach ($suggestion->ruleset as $layer => $allowed) {
            $allowedLabel = $allowed === [] ? '(no dependencies allowed)' : implode(', ', $allowed);
            $this->line("    {$layer} → {$allowedLabel}");
        }
    }

    private function promptDeptracDecision(): string
    {
        /** @var string $decision */
        $decision = select(
            label: 'How do you want to handle the suggested Deptrac layers?',
            options: [
                'use' => 'Use suggested layers (write to deptrac.yaml)',
                'edit' => 'Write and open in $EDITOR for manual tweaks',
                'skip' => 'Skip — keep default stub',
            ],
            default: 'use',
            hint: 'You can always edit deptrac.yaml later.',
        );

        return $decision;
    }

    private function openInEditor(string $filePath): void
    {
        $editor = (string) (getenv('EDITOR') ?: 'nano');

        $this->components->bulletList(["Opening {$filePath} in {$editor}..."]);

        $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = proc_open([$editor, $filePath], $descriptors, $pipes);

        if (is_resource($process)) {
            proc_close($process);
        }
    }

    private function maybeInstallCaptainhook(
        Preset $preset,
        EnvironmentInfo $env,
        CaptainhookInstaller $installer,
        InstallSummary $summary,
    ): CaptainhookInstallResult {
        $this->line('');
        $this->components->info('CaptainHook setup');

        $result = $installer->install($env);
        $this->renderCaptainhookResult($result);
        $this->recordCaptainhookOutcome($result, $summary);

        return $result;
    }

    private function recordCaptainhookOutcome(CaptainhookInstallResult $result, InstallSummary $summary): void
    {
        $firstLine = static function (?string $message): string {
            if ($message === null || $message === '') {
                return 'no stderr captured.';
            }

            $line = explode("\n", $message, 2)[0];

            return $line !== '' ? $line : 'no stderr captured.';
        };

        match ($result->status) {
            CaptainhookInstallStatus::BinaryMissing => $summary->warn(new InstallWarning(
                level: WarningLevel::Warning,
                code: WarningCode::CaptainhookBinaryMissing,
                message: 'CaptainHook binary not found at vendor/bin/captainhook.',
                remediation: 'Run `composer install`; if the plugin is blocked, run `composer config allow-plugins.captainhook/hook-installer true` first.',
            )),
            CaptainhookInstallStatus::Failed => $summary->warn(new InstallWarning(
                level: WarningLevel::Error,
                code: WarningCode::CaptainhookInstallFailed,
                message: 'CaptainHook install exited non-zero: '.$firstLine($result->message),
                remediation: 'Re-run `vendor/bin/captainhook install --force --only-enabled` manually to see full output.',
            )),
            CaptainhookInstallStatus::Installed,
            CaptainhookInstallStatus::Skipped => null,
        };
    }

    private function renderCaptainhookResult(CaptainhookInstallResult $result): void
    {
        $status = match ($result->status) {
            CaptainhookInstallStatus::Installed => '<fg=green>installed (.git/hooks registered)</>',
            CaptainhookInstallStatus::BinaryMissing => '<fg=yellow>binary missing</>',
            CaptainhookInstallStatus::Skipped => '<fg=gray>skipped</>',
            CaptainhookInstallStatus::Failed => '<fg=red>failed</>',
        };

        $this->components->twoColumnDetail('captainhook install', $status);

        if ($result->message !== null && $result->message !== '') {
            foreach (explode("\n", $result->message) as $line) {
                if ($line !== '') {
                    $this->line('  '.$line);
                }
            }
        }
    }

    private function renderNextSteps(Preset $preset, NextStepsReporter $reporter): int
    {
        $count = 0;
        foreach ($reporter->nextSteps($preset) as $step) {
            $this->components->twoColumnDetail(
                $step['gate'],
                $step['action'],
            );
            $this->line('    <fg=cyan>→</> '.$step['command']);
            $count++;
        }

        return $count;
    }
}
