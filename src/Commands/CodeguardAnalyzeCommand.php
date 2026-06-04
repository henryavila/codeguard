<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Commands;

use Henryavila\Codeguard\Analyze\AnalyzeBaseline;
use Henryavila\Codeguard\Analyze\AnalyzeResult;
use Henryavila\Codeguard\Analyze\AnalyzeRunner;
use Henryavila\Codeguard\Analyze\FileScopeResolver;
use Henryavila\Codeguard\Analyze\LlmClient;
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
        {--samples=1 : Review passes the skill should run; a finding survives only if ≥2/3 of samples agree (R1 voting).}
        {--critique : Ask the skill to run a critique re-scoring pass; findings scored 0 are dropped (R2).}
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
        LlmClient $llm,
    ): int {
        if ((bool) $this->option('emit')) {
            return $this->handleEmit($config, $runner, $scope);
        }

        $ingest = $this->option('ingest');
        if (is_string($ingest) && $ingest !== '') {
            return $this->handleIngest($config, $runner, $scope, $recorder, $baseline, $ingest);
        }

        // No real adjudicating driver → context-emit is the supported transport.
        // Inform and fall back to writing a work order for /codeguard-review,
        // instead of a dead-end notice. The synchronous path below runs only
        // when a driver (e.g. an API client) is bound in place of NullLlmClient.
        if (! $llm->isConfigured()) {
            $this->components->info(
                'No LLM driver configured — emitting a work order for context-emit review '
                .'(run /codeguard-review, or --ingest its findings). Uses your Claude Code subscription, no metered API.',
            );

            return $this->handleEmit($config, $runner, $scope);
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
        $samples = $this->resolveSamples();
        $critique = (bool) $this->option('critique');
        $files = $this->resolveFiles($scope);
        $workOrder = $runner->buildWorkOrder($files, $config->enabledPresets, $samples, $critique);

        $out = (string) ($this->option('out') ?: base_path('.codeguard'.DIRECTORY_SEPARATOR.'analyze-request.json'));
        $dir = dirname($out);
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $json = json_encode($workOrder, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($out, ($json !== false ? $json : '{}')."\n");

        $this->components->info(sprintf(
            'Wrote %d analysis unit(s)%s to %s',
            count($workOrder['units']),
            $samples > 1 ? sprintf(' (×%d voting samples)', $samples) : '',
            $out,
        ));

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
        $decoded = $contents === false ? null : json_decode($contents, true);

        $files = $this->resolveFiles($scope);

        $samples = $this->extractSamples($decoded);
        if ($samples !== null) {
            $result = $runner->ingestSamples(
                $files,
                $config->enabledPresets,
                $samples,
                $failOn,
                $this->minVotesFor(count($samples)),
            );
        } else {
            $result = $runner->ingest($files, $config->enabledPresets, $this->normalizeRawFindings($decoded), $failOn);
        }

        $this->maybeAccept($baseline, $result);
        $this->renderFindings($result);

        $exitCode = $result->failed($failOn) ? self::FAILURE : self::SUCCESS;
        $this->emitCommandEnd($recorder, $exitCode, $startHrtime);

        return $exitCode;
    }

    /**
     * Number of voting samples to request on --emit. Capped so a typo cannot
     * fan out an absurd number of subagent passes.
     */
    private function resolveSamples(): int
    {
        $raw = $this->option('samples');
        $n = is_numeric($raw) ? (int) $raw : 1;

        return max(1, min(9, $n));
    }

    /**
     * Votes required for a finding to survive: ≥2/3 of the samples (R1). For
     * k=1 this is 1 (single-sample behaves like the legacy ingest path).
     */
    private function minVotesFor(int $sampleCount): int
    {
        return max(1, (int) ceil($sampleCount * 2 / 3));
    }

    /**
     * Detect a multi-sample ballot — a `{ "samples": [[...], [...]] }` envelope.
     * Returns one normalized findings list per sample, or null when the payload
     * is a single-sample findings array (handled by {@see normalizeRawFindings}).
     *
     * @return list<list<array<string, mixed>>>|null
     */
    private function extractSamples(mixed $decoded): ?array
    {
        if (! is_array($decoded) || ! isset($decoded['samples']) || ! is_array($decoded['samples'])) {
            return null;
        }

        $samples = [];
        foreach ($decoded['samples'] as $sample) {
            if (is_array($sample)) {
                $samples[] = $this->normalizeRawFindings($sample);
            }
        }

        return $samples;
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
                '  %s %s:%d · %s · %s (%.2f)%s%s',
                $this->glyph($match->severity),
                $match->file,
                $match->line,
                $match->patternKey,
                $match->message,
                $match->confidence,
                $match->verifiedScore !== null ? sprintf(' [score %d/10]', $match->verifiedScore) : '',
                $match->relatedFile !== null ? sprintf(' → %s', $match->relatedFile) : '',
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
