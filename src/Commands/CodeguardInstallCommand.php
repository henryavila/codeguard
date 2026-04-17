<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Commands;

use Henryavila\Codeguard\Install\DeptracLayerSuggester;
use Henryavila\Codeguard\Install\DeptracLayerWizard;
use Henryavila\Codeguard\Install\EnvironmentDetector;
use Henryavila\Codeguard\Install\EnvironmentInfo;
use Henryavila\Codeguard\Install\GatePlan;
use Henryavila\Codeguard\Install\GatePlanRegistry;
use Henryavila\Codeguard\Install\LayerDecisionStore;
use Henryavila\Codeguard\Install\LayerSuggestion;
use Henryavila\Codeguard\Install\LefthookInstallResult;
use Henryavila\Codeguard\Install\LefthookInstallStatus;
use Henryavila\Codeguard\Install\LefthookInstaller;
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
use Henryavila\Codeguard\Testing\Preset;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

use function Laravel\Prompts\select;

final class CodeguardInstallCommand extends Command
{
    protected $signature = 'codeguard:install
        {--preset= : Force preset (default|full). Skip auto-detection.}
        {--no-interactive : CI mode — use auto-detection, no prompts.}
        {--refresh-stubs : Overwrite existing stubs after diff review.}
        {--no-deptrac-wizard : Skip the guided layer-classification wizard (use heuristic only).}';

    protected $description = 'Guided install — detects environment, selects preset, publishes stubs with diff-aware re-run, suggests Deptrac layers, installs Lefthook, prints next-steps.';

    public function handle(
        EnvironmentDetector $detector,
        PresetSelector $presetSelector,
        StubRegistry $registry,
        GatePlanRegistry $planRegistry,
        StubPublisher $publisher,
        DeptracLayerSuggester $deptracSuggester,
        DeptracLayerWizard $deptracWizard,
        LayerDecisionStore $layerDecisionStore,
        LefthookInstaller $lefthookInstaller,
        PhpstanExtensionSelector $phpstanExtSelector,
        PhpstanExtensionStore $phpstanExtStore,
        PhpstanExtensionApplier $phpstanExtApplier,
        NextStepsReporter $reporter,
        Filesystem $filesystem,
    ): int {
        $interactive = ! $this->option('no-interactive');
        $forceOverwrite = (bool) $this->option('refresh-stubs');
        $skipWizard = (bool) $this->option('no-deptrac-wizard');
        $presetFlag = $this->option('preset');

        $this->renderHeader();

        $environment = $detector->detect();
        $this->renderEnvironment($environment);

        $recommended = $presetSelector->autoSelect($environment);
        $this->renderRecommendation($recommended, $environment);

        $preset = $this->resolvePreset($environment, $presetSelector, $presetFlag, $interactive);
        $this->line('');
        $this->components->twoColumnDetail('Selected preset', $preset->label());

        $plans = $planRegistry->plansFor($preset);
        $this->renderGatePlan($plans, $planRegistry);

        if (! $this->confirmProceed($interactive)) {
            $this->components->warn('Install aborted.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->components->info('Publishing stubs...');

        $publisher->useOutput($this->output);

        $stubs = $registry->stubsFor($preset);
        $results = $publisher->publish($stubs, $forceOverwrite, $interactive);

        $this->renderStubResults($results);

        if ($this->hasFailures($results)) {
            $this->components->error('One or more stubs failed to publish. See messages above.');

            return self::FAILURE;
        }

        $this->applyPhpstanExtensionChoice(
            phpstanExtSelector: $phpstanExtSelector,
            phpstanExtStore: $phpstanExtStore,
            phpstanExtApplier: $phpstanExtApplier,
            interactive: $interactive,
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
        $this->maybeInstallLefthook($preset, $environment, $lefthookInstaller);

        $this->line('');
        $this->components->info('Next steps:');
        $this->renderNextSteps($preset, $reporter);

        $this->line('');
        $this->components->twoColumnDetail('Docs', $reporter->documentationUrl());

        return self::SUCCESS;
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
            'Lefthook binary',
            $env->hasLefthookBinary ? 'available' : '<fg=gray>not installed</>',
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
     */
    private function renderGatePlan(array $plans, GatePlanRegistry $registry): void
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

    private function applyPhpstanExtensionChoice(
        PhpstanExtensionSelector $phpstanExtSelector,
        PhpstanExtensionStore $phpstanExtStore,
        PhpstanExtensionApplier $phpstanExtApplier,
        bool $interactive,
    ): void {
        $phpstanPath = $this->laravel->basePath('phpstan.neon');

        if (! file_exists($phpstanPath)) {
            return;
        }

        $this->line('');
        $this->components->info('PHPStan extensions');

        $saved = $phpstanExtStore->load();

        $selected = $interactive
            ? $phpstanExtSelector->prompt($saved === [] ? PhpstanExtension::defaultEnabled() : $saved)
            : $phpstanExtSelector->autoResolve($saved);

        $phpstanExtApplier->apply($phpstanPath, $selected);
        $phpstanExtStore->save($selected);

        $activeNames = array_map(
            static fn (PhpstanExtension $ext): string => $ext->displayName(),
            $selected,
        );

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
            $layer = $namespace->suggestedLayer ?? '<fg=gray>(unclassified)</>';
            $this->line(sprintf(
                '  %s  <fg=gray>(%d files)</>  → <fg=cyan>%s</>',
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

    private function maybeInstallLefthook(
        Preset $preset,
        EnvironmentInfo $env,
        LefthookInstaller $installer,
    ): void {
        $this->line('');
        $this->components->info('Lefthook setup');

        $result = $installer->install($env);
        $this->renderLefthookResult($result);
    }

    private function renderLefthookResult(LefthookInstallResult $result): void
    {
        $status = match ($result->status) {
            LefthookInstallStatus::Installed => '<fg=green>installed (.git/hooks registered)</>',
            LefthookInstallStatus::BinaryMissing => '<fg=yellow>binary missing</>',
            LefthookInstallStatus::Skipped => '<fg=gray>skipped</>',
            LefthookInstallStatus::Failed => '<fg=red>failed</>',
        };

        $this->components->twoColumnDetail('lefthook install', $status);

        if ($result->message !== null && $result->message !== '') {
            foreach (explode("\n", $result->message) as $line) {
                if ($line !== '') {
                    $this->line('  '.$line);
                }
            }
        }
    }

    private function renderNextSteps(Preset $preset, NextStepsReporter $reporter): void
    {
        foreach ($reporter->nextSteps($preset) as $step) {
            $this->components->twoColumnDetail(
                $step['gate'],
                $step['action'],
            );
            $this->line('    <fg=cyan>→</> '.$step['command']);
        }
    }
}
