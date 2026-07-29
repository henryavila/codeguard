<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Commands;

use Henryavila\Codeguard\Analyze\AnalyzeBaseline;
use Henryavila\Codeguard\Analyze\AnalyzeOptions;
use Henryavila\Codeguard\Analyze\AnalyzeResult;
use Henryavila\Codeguard\Analyze\AnalyzeRunner;
use Henryavila\Codeguard\Analyze\FileScopeResolver;
use Henryavila\Codeguard\Analyze\FindingAction;
use Henryavila\Codeguard\Analyze\FindingActionClassifier;
use Henryavila\Codeguard\Analyze\LlmClient;
use Henryavila\Codeguard\Analyze\PatternMatch;
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
        {--base= : Diff against this git ref (e.g. origin/main). Overrides default changed-only when set.}
        {--committed-only : With --base, only committed commits on the branch (ignore dirty worktree).}
        {--fail-on=critical : Exit non-zero at/above this severity — critical|warning|suggestion|never.}
        {--context=manual : Telemetry context — pre-commit|pre-push|ci|manual.}
        {--emit : Write a work order JSON (for the codeguard-review Claude skill) instead of calling an LLM.}
        {--ingest= : Validate + report findings from this JSON file (produced out-of-band by the skill).}
        {--request= : Work order JSON path for ingest scope reuse (default .codeguard/analyze-request.json).}
        {--allow-scope-drift : Ingest even if HEAD SHA differs from work order scope.head_sha.}
        {--force : Allow overwriting an existing non-empty work order with an empty emit.}
        {--samples=1 : Review passes the skill should run; a finding survives only if ≥2/3 of samples agree (R1 voting).}
        {--critique : Ask the skill to run a critique re-scoring pass; low verified_scores are dropped (R2, see --min-critique-score).}
        {--focus= : Pattern focus — full (all patterns) or contractor (G3 security + architecture only). Defaults from config.}
        {--min-critique-score= : Drop findings with verified_score below this (0-10). Uncritiqued kept. Default: 1 full / 4 contractor.}
        {--only-patterns= : Comma-separated pattern key allowlist (overrides --focus keys).}
        {--include-hygiene : With --focus=full, include hygiene patterns (types, dry, small-functions, …).}
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
        $options = $this->resolveAnalyzeOptions($config);
        $resolved = $this->resolveScope($scope);

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

        $result = $runner->run($resolved['files'], $config->enabledPresets, $failOn, $context, $options);

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
        $options = $this->resolveAnalyzeOptions($config);
        $resolved = $this->resolveScope($scope);
        $workOrder = $runner->buildWorkOrder(
            $resolved['files'],
            $config->enabledPresets,
            $samples,
            $critique,
            $options,
            $resolved,
        );

        $out = (string) ($this->option('out') ?: base_path('.codeguard'.DIRECTORY_SEPARATOR.'analyze-request.json'));

        if (! $this->mayWriteWorkOrder($out, $workOrder)) {
            return self::FAILURE;
        }

        $dir = dirname($out);
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $json = json_encode($workOrder, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($out, ($json !== false ? $json : '{}')."\n");

        $focusNote = $options->onlyPatternKeys !== null
            ? sprintf(' [focus=contractor, %d keys, min-critique=%d]', count($options->onlyPatternKeys), $options->minCritiqueScore)
            : ($options->excludeClassifications !== []
                ? sprintf(' [focus=full, exclude=%s]', implode(',', $options->excludeClassifications))
                : '');

        $this->components->info(sprintf(
            'Wrote %d analysis unit(s)%s%s to %s',
            count($workOrder['units']),
            $samples > 1 ? sprintf(' (×%d voting samples)', $samples) : '',
            $focusNote,
            $out,
        ));

        if (count($workOrder['units']) === 0) {
            $this->components->warn(
                'Empty work order (0 units). Try --path=…, --base=origin/main, --all, or --include-hygiene; '
                .'or pass --force only if intentionally overwriting a previous non-empty emit.',
            );
        }

        return self::SUCCESS;
    }

    /**
     * Abort when --out already has units>0 and the new work order is empty, unless --force.
     *
     * @param  array<string, mixed>  $workOrder
     */
    private function mayWriteWorkOrder(string $out, array $workOrder): bool
    {
        if ((bool) $this->option('force') || ! is_file($out)) {
            return true;
        }

        $newUnits = is_array($workOrder['units'] ?? null) ? count($workOrder['units']) : 0;
        if ($newUnits > 0) {
            return true;
        }

        $existingRaw = file_get_contents($out);
        $existing = $existingRaw === false ? null : json_decode($existingRaw, true);
        if (! is_array($existing)) {
            return true;
        }

        $existingUnits = is_array($existing['units'] ?? null) ? count($existing['units']) : 0;
        if ($existingUnits === 0) {
            return true;
        }

        $this->components->error(sprintf(
            'Refusing to overwrite non-empty work order (%d unit(s)) with an empty emit at %s. '
            .'Pass --force to overwrite, or fix scope (--path / --base / --all).',
            $existingUnits,
            $out,
        ));

        return false;
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

        $request = $this->loadRequestWorkOrder();
        $options = $this->resolveIngestOptions($config, $request);
        $files = $this->resolveIngestFiles($scope, $request);
        if ($files === null) {
            $this->emitCommandEnd($recorder, self::FAILURE, $startHrtime);

            return self::FAILURE;
        }

        $samples = $this->extractSamples($decoded);
        if ($samples !== null) {
            $result = $runner->ingestSamples(
                $files,
                $config->enabledPresets,
                $samples,
                $failOn,
                $this->minVotesFor(count($samples)),
                $options,
            );
        } else {
            $result = $runner->ingest(
                $files,
                $config->enabledPresets,
                $this->normalizeRawFindings($decoded),
                $failOn,
                $options,
            );
        }

        $this->maybeAccept($baseline, $result);
        $this->renderFindings($result);

        $exitCode = $result->failed($failOn) ? self::FAILURE : self::SUCCESS;
        $this->emitCommandEnd($recorder, $exitCode, $startHrtime);

        return $exitCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRequestWorkOrder(): ?array
    {
        $path = $this->option('request');
        $requestPath = is_string($path) && $path !== ''
            ? $path
            : base_path('.codeguard'.DIRECTORY_SEPARATOR.'analyze-request.json');

        if (! is_file($requestPath)) {
            return null;
        }

        $raw = file_get_contents($requestPath);
        $decoded = $raw === false ? null : json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Prefer work-order focus/min_critique when CLI did not set them explicitly.
     *
     * @param  array<string, mixed>|null  $request
     */
    private function resolveIngestOptions(CodeguardConfig $config, ?array $request): AnalyzeOptions
    {
        $focusOpt = $this->option('focus');
        $focusExplicit = is_string($focusOpt) && $focusOpt !== '';
        $focus = $focusExplicit
            ? $focusOpt
            : (is_string($request['focus'] ?? null) && $request['focus'] !== ''
                ? (string) $request['focus']
                : $config->patternsFocus);

        $minOpt = $this->option('min-critique-score');
        $minExplicit = is_numeric($minOpt);
        $minScore = $minExplicit
            ? (int) $minOpt
            : (is_numeric($request['min_critique_score'] ?? null)
                ? (int) $request['min_critique_score']
                : $config->minCritiqueScore);

        $onlyOpt = $this->option('only-patterns');
        $onlyKeys = null;
        if (is_string($onlyOpt) && trim($onlyOpt) !== '') {
            $onlyKeys = array_values(array_filter(array_map(
                static fn (string $key): string => trim($key),
                explode(',', $onlyOpt),
            )));
        }

        $includeHygiene = (bool) $this->option('include-hygiene')
            || $config->includeHygiene;

        $contractorKeys = $config->contractorPatternKeys !== []
            ? $config->contractorPatternKeys
            : null;

        return AnalyzeOptions::resolve(
            focus: $focus,
            minCritiqueScore: $minScore,
            onlyPatternKeys: $onlyKeys,
            contractorKeys: $contractorKeys,
            includeHygiene: $includeHygiene,
        );
    }

    /**
     * Reuse scope.files from the work order when present; otherwise re-resolve CLI scope.
     * Fails on HEAD SHA drift unless --allow-scope-drift.
     *
     * @param  array<string, mixed>|null  $request
     * @return list<string>|null  null on hard failure
     */
    private function resolveIngestFiles(FileScopeResolver $scope, ?array $request): ?array
    {
        $scopeMeta = is_array($request['scope'] ?? null) ? $request['scope'] : null;
        $recordedFiles = is_array($scopeMeta['files'] ?? null) ? $scopeMeta['files'] : null;

        if (is_array($recordedFiles) && $recordedFiles !== []) {
            $headSha = is_string($scopeMeta['head_sha'] ?? null) ? $scopeMeta['head_sha'] : null;
            if ($headSha !== null && $headSha !== '') {
                $current = $scope->headSha();
                if ($current !== null && $current !== $headSha) {
                    $this->components->warn(sprintf(
                        'Scope drift: work order head_sha=%s, current HEAD=%s.',
                        $headSha,
                        $current,
                    ));
                    if (! (bool) $this->option('allow-scope-drift')) {
                        $this->components->error(
                            'Refusing ingest with drifted HEAD. Re-emit, or pass --allow-scope-drift.',
                        );

                        return null;
                    }
                }
            }

            $existing = [];
            foreach ($recordedFiles as $file) {
                if (is_string($file) && is_file($file) && str_ends_with($file, '.php')) {
                    $existing[] = $file;
                }
            }

            return $existing;
        }

        return $this->resolveScope($scope)['files'];
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
     * Priority: --path > --all > --base > changed-only.
     *
     * @return array{
     *     mode: string,
     *     base_ref: string|null,
     *     committed_only: bool,
     *     path: string|null,
     *     head_sha: string|null,
     *     merge_base_sha: string|null,
     *     files: list<string>
     * }
     */
    private function resolveScope(FileScopeResolver $scope): array
    {
        $path = $this->option('path');
        $base = $this->option('base');
        $committedOnly = (bool) $this->option('committed-only');
        $headSha = $scope->headSha();

        if (is_string($path) && $path !== '') {
            return [
                'mode' => 'path',
                'base_ref' => null,
                'committed_only' => false,
                'path' => $path,
                'head_sha' => $headSha,
                'merge_base_sha' => null,
                'files' => $scope->path($path),
            ];
        }

        if ((bool) $this->option('all')) {
            return [
                'mode' => 'all',
                'base_ref' => null,
                'committed_only' => false,
                'path' => null,
                'head_sha' => $headSha,
                'merge_base_sha' => null,
                'files' => $scope->all(),
            ];
        }

        if (is_string($base) && $base !== '') {
            return [
                'mode' => 'base',
                'base_ref' => $base,
                'committed_only' => $committedOnly,
                'path' => null,
                'head_sha' => $headSha,
                'merge_base_sha' => $scope->mergeBaseSha($base),
                'files' => $scope->againstBase($base, $committedOnly),
            ];
        }

        return [
            'mode' => 'changed_only',
            'base_ref' => null,
            'committed_only' => false,
            'path' => null,
            'head_sha' => $headSha,
            'merge_base_sha' => null,
            'files' => $scope->changedOnly(),
        ];
    }

    private function resolveFailOn(): ?Severity
    {
        $raw = (string) ($this->option('fail-on') ?: 'critical');

        if ($raw === 'never') {
            return null;
        }

        return Severity::tryFrom($raw) ?? Severity::Critical;
    }

    /**
     * Merge CLI flags with config for focus / critique floor / key allowlist.
     * Explicit CLI always wins; config supplies defaults.
     */
    private function resolveAnalyzeOptions(CodeguardConfig $config): AnalyzeOptions
    {
        $focusOpt = $this->option('focus');
        $focus = is_string($focusOpt) && $focusOpt !== ''
            ? $focusOpt
            : $config->patternsFocus;

        $minOpt = $this->option('min-critique-score');
        $minScore = is_numeric($minOpt) ? (int) $minOpt : $config->minCritiqueScore;

        $onlyOpt = $this->option('only-patterns');
        $onlyKeys = null;
        if (is_string($onlyOpt) && trim($onlyOpt) !== '') {
            $onlyKeys = array_values(array_filter(array_map(
                static fn (string $key): string => trim($key),
                explode(',', $onlyOpt),
            )));
        }

        $includeHygiene = (bool) $this->option('include-hygiene')
            || $config->includeHygiene;

        $contractorKeys = $config->contractorPatternKeys !== []
            ? $config->contractorPatternKeys
            : null;

        return AnalyzeOptions::resolve(
            focus: $focus,
            minCritiqueScore: $minScore,
            onlyPatternKeys: $onlyKeys,
            contractorKeys: $contractorKeys,
            includeHygiene: $includeHygiene,
        );
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

        $grouped = $result->matchesByAction();
        $block = $grouped[FindingAction::Block->value];
        $request = $grouped[FindingAction::RequestChange->value];
        $info = $grouped[FindingAction::Info->value];

        $this->renderActionSection(FindingAction::Block, $block, 'do not merge until fixed');
        $this->renderActionSection(FindingAction::RequestChange, $request, null);
        $this->renderActionSection(FindingAction::Info, $info, null);

        $this->line('');
        $this->line('Checklist (markdown):');
        foreach ([...$block, ...$request, ...$info] as $match) {
            $action = (new FindingActionClassifier)->classify($match);
            $this->line(sprintf(
                '- [ ] **%s** `%s:%d` — %s — %s',
                $action->sectionTitle(),
                $this->displayPath($match->file),
                $match->line,
                $match->patternKey,
                $this->oneLine($match->message),
            ));
        }

        $this->line('');
        $this->components->info(sprintf(
            '%d finding(s) across %d checks. block=%d request_change=%d info=%d%s',
            count($result->matches),
            $result->patternsChecked,
            count($block),
            count($request),
            count($info),
            $this->suppressedSuffix($result),
        ));
    }

    /**
     * @param  list<PatternMatch>  $matches
     */
    private function renderActionSection(FindingAction $action, array $matches, ?string $hint): void
    {
        $title = sprintf('## %s (%d)', $action->sectionTitle(), count($matches));
        if ($hint !== null && $matches !== []) {
            $title .= ' — '.$hint;
        }
        $this->line($title);

        if ($matches === []) {
            $this->line('  (none)');

            return;
        }

        foreach ($matches as $match) {
            $this->line(sprintf(
                '  %s %s:%d · %s · %s (%.2f)%s%s',
                $this->glyph($match->severity),
                $this->displayPath($match->file),
                $match->line,
                $match->patternKey,
                $match->message,
                $match->confidence,
                $match->verifiedScore !== null ? sprintf(' [score %d/10]', $match->verifiedScore) : '',
                $match->relatedFile !== null ? sprintf(' → %s', $match->relatedFile) : '',
            ));
        }
    }

    private function displayPath(string $file): string
    {
        $base = (string) base_path();
        if ($base !== '' && str_starts_with($file, $base.DIRECTORY_SEPARATOR)) {
            return substr($file, strlen($base) + 1);
        }

        return $file;
    }

    private function oneLine(string $message): string
    {
        $line = preg_replace('/\s+/', ' ', trim($message)) ?? $message;

        return mb_strlen($line) > 120 ? mb_substr($line, 0, 117).'…' : $line;
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
