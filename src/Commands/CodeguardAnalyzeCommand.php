<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Commands;

use Henryavila\Codeguard\Analyze\AnalyzeBaseline;
use Henryavila\Codeguard\Analyze\AnalyzeResult;
use Henryavila\Codeguard\Analyze\AnalyzeRunner;
use Henryavila\Codeguard\Analyze\FileScopeResolver;
use Henryavila\Codeguard\Analyze\Severity;
use Henryavila\Codeguard\Telemetry\EventName;
use Henryavila\Codeguard\Telemetry\EventStatus;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Testing\CodeguardConfig;
use Illuminate\Console\Command;

final class CodeguardAnalyzeCommand extends Command
{
    protected $signature = 'codeguard:analyze
        {--changed-only : Analyze only git-changed + staged files (default scope).}
        {--path= : Narrow scope to a file or subtree.}
        {--all : Full scan of every detection-matched file (CI/manual).}
        {--fail-on=critical : Exit non-zero at/above this severity — critical|warning|suggestion|never.}
        {--context=manual : Telemetry context — pre-commit|pre-push|ci|manual.}
        {--emit : Write a work order JSON (for the codeguard-review Claude skill) instead of calling an LLM.}
        {--ingest= : Validate + report findings from this JSON file (produced out-of-band by the skill).}
        {--out= : Output path for --emit (default .codeguard/analyze-request.json).}
        {--accept : Accept the surviving findings into the baseline so future runs suppress them.}';

    protected $description = 'Run pattern-based review over scoped files and report findings.';

    private const ALLOWED_CONTEXTS = ['pre-commit', 'pre-push', 'ci', 'manual'];

    public function handle(
        CodeguardConfig $config,
        AnalyzeRunner $runner,
        FileScopeResolver $scope,
        Recorder $recorder,
        AnalyzeBaseline $baseline,
    ): int {
        if ((bool) $this->option('emit')) {
            return $this->handleEmit($config, $runner, $scope);
        }

        $ingest = $this->option('ingest');
        if (is_string($ingest) && $ingest !== '') {
            return $this->handleIngest($config, $runner, $scope, $recorder, $baseline, $ingest);
        }

        $context = $this->resolveContext();
        $failOn = $this->resolveFailOn();

        $startHrtime = hrtime(true);
        $recorder->record(
            event: EventName::CommandStart,
            status: EventStatus::Ok,
            durationMs: 0,
            extras: [
                'command' => 'analyze',
                'preset_flag' => null,
            ],
        );

        $files = $this->resolveFiles($scope);
        $result = $runner->run($files, $config->enabledPresets, $failOn, $context);

        if (! $result->adjudicated) {
            $this->components->warn(
                'LLM driver not configured — set config(\'codeguard.patterns.driver\'). No patterns adjudicated.',
            );
            $this->emitCommandEnd($recorder, self::SUCCESS, $startHrtime);

            return self::SUCCESS;
        }

        $this->maybeAccept($baseline, $result);
        $this->renderFindings($result);

        $exitCode = $result->failed($failOn) ? self::FAILURE : self::SUCCESS;
        $this->emitCommandEnd($recorder, $exitCode, $startHrtime);

        return $exitCode;
    }

    private function maybeAccept(AnalyzeBaseline $baseline, AnalyzeResult $result): void
    {
        if ((bool) $this->option('accept') && $result->matches !== []) {
            $added = $baseline->accept($result->matches);
            $this->components->info(sprintf('Accepted %d finding(s) into the baseline.', $added));
        }
    }

    private function handleEmit(CodeguardConfig $config, AnalyzeRunner $runner, FileScopeResolver $scope): int
    {
        $files = $this->resolveFiles($scope);
        $workOrder = $runner->buildWorkOrder($files, $config->enabledPresets);

        $out = (string) ($this->option('out') ?: base_path('.codeguard'.DIRECTORY_SEPARATOR.'analyze-request.json'));
        $dir = dirname($out);
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $json = json_encode($workOrder, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($out, ($json !== false ? $json : '{}')."\n");

        $this->components->info(sprintf('Wrote %d analysis unit(s) to %s', count($workOrder['units']), $out));

        return self::SUCCESS;
    }

    private function handleIngest(
        CodeguardConfig $config,
        AnalyzeRunner $runner,
        FileScopeResolver $scope,
        Recorder $recorder,
        AnalyzeBaseline $baseline,
        string $ingestPath,
    ): int {
        $failOn = $this->resolveFailOn();
        $startHrtime = hrtime(true);
        $recorder->record(
            event: EventName::CommandStart,
            status: EventStatus::Ok,
            durationMs: 0,
            extras: ['command' => 'analyze', 'preset_flag' => null],
        );

        if (! is_file($ingestPath)) {
            $this->components->error(sprintf('Findings file not found: %s', $ingestPath));
            $this->emitCommandEnd($recorder, self::FAILURE, $startHrtime);

            return self::FAILURE;
        }

        $contents = file_get_contents($ingestPath);
        $findings = $this->normalizeRawFindings($contents === false ? null : json_decode($contents, true));

        $files = $this->resolveFiles($scope);
        $result = $runner->ingest($files, $config->enabledPresets, $findings, $failOn);

        $this->maybeAccept($baseline, $result);
        $this->renderFindings($result);

        $exitCode = $result->failed($failOn) ? self::FAILURE : self::SUCCESS;
        $this->emitCommandEnd($recorder, $exitCode, $startHrtime);

        return $exitCode;
    }

    /**
     * Accepts either a bare findings array or a `{ "findings": [...] }` envelope.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeRawFindings(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        $list = array_is_list($decoded) ? $decoded : ($decoded['findings'] ?? []);
        if (! is_array($list)) {
            return [];
        }

        $findings = [];
        foreach ($list as $item) {
            if (is_array($item)) {
                $findings[] = $item;
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function resolveFiles(FileScopeResolver $scope): array
    {
        $path = $this->option('path');
        if (is_string($path) && $path !== '') {
            return $scope->path($path);
        }

        if ((bool) $this->option('all')) {
            return $scope->all();
        }

        return $scope->changedOnly();
    }

    private function resolveFailOn(): ?Severity
    {
        $raw = (string) ($this->option('fail-on') ?: 'critical');

        if ($raw === 'never') {
            return null;
        }

        return Severity::tryFrom($raw) ?? Severity::Critical;
    }

    private function renderFindings(AnalyzeResult $result): void
    {
        if ($result->matches === []) {
            $this->components->info(sprintf(
                'No pattern findings (%d checks).%s',
                $result->patternsChecked,
                $this->suppressedSuffix($result),
            ));

            return;
        }

        foreach ($result->matches as $match) {
            $this->line(sprintf(
                '  %s %s:%d · %s · %s (%.2f)',
                $this->glyph($match->severity),
                $match->file,
                $match->line,
                $match->patternKey,
                $match->message,
                $match->confidence,
            ));
        }

        $this->line('');
        $this->components->info(sprintf(
            '%d finding(s) across %d checks.%s',
            count($result->matches),
            $result->patternsChecked,
            $this->suppressedSuffix($result),
        ));
    }

    private function suppressedSuffix(AnalyzeResult $result): string
    {
        return $result->suppressedCount > 0
            ? sprintf(' %d suppressed via baseline.', $result->suppressedCount)
            : '';
    }

    private function glyph(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical => '✗',
            Severity::Warning => '⚠',
            Severity::Suggestion => '→',
        };
    }

    private function resolveContext(): string
    {
        $raw = (string) ($this->option('context') ?: 'manual');

        return in_array($raw, self::ALLOWED_CONTEXTS, true) ? $raw : 'manual';
    }

    private function emitCommandEnd(Recorder $recorder, int $exitCode, int $startHrtime): void
    {
        $durationMs = (int) round((hrtime(true) - $startHrtime) / 1_000_000);

        $recorder->record(
            event: EventName::CommandEnd,
            status: $exitCode === 0 ? EventStatus::Ok : EventStatus::Fail,
            durationMs: $durationMs,
            extras: [
                'command' => 'analyze',
                'exit_code' => max(0, min(255, $exitCode)),
            ],
        );
    }
}
