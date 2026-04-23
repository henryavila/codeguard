<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Commands;

use Henryavila\Codeguard\Telemetry\EventName;
use Henryavila\Codeguard\Telemetry\EventStatus;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Testing\AsyncCommandExecutor;
use Henryavila\Codeguard\Testing\CodeguardConfig;
use Henryavila\Codeguard\Testing\StageConfig;
use Henryavila\Codeguard\Testing\TestStageResult;
use Henryavila\Codeguard\Testing\TestSuiteRunner;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class CodeguardTestCommand extends Command
{
    protected $signature = 'codeguard:test
        {--stage= : Limit run to a single stage key (e.g. unit, feature).}
        {--mode=fast-fail : Execution mode — fast-fail (abort on first failure) or report (run all).}
        {--no-coverage : Skip coverage instrumentation. Default: coverage on.}
        {--context=manual : Telemetry context — manual|ci|pre-push.}';

    protected $description = 'Run configured test stages through the CodeGuard TestSuiteRunner.';

    private const ALLOWED_MODES = ['fast-fail', 'report'];

    private const ALLOWED_CONTEXTS = ['manual', 'ci', 'pre-push'];

    public function handle(
        CodeguardConfig $config,
        AsyncCommandExecutor $executor,
        Filesystem $filesystem,
        Recorder $recorder,
    ): int {
        $mode = $this->resolveMode();
        $withCoverage = ! (bool) $this->option('no-coverage');
        $context = $this->resolveContext();

        $commandStartHrtime = hrtime(true);
        $recorder->record(
            event: EventName::CommandStart,
            status: EventStatus::Ok,
            durationMs: 0,
            extras: [
                'command' => 'test',
                'preset_flag' => null,
            ],
        );

        $stages = $this->resolveStages($config);
        if ($stages === []) {
            $this->components->warn('No matching test stages to run.');
            $this->emitCommandEnd($recorder, self::SUCCESS, $commandStartHrtime);

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Running %d stage(s) — mode: %s, coverage: %s, context: %s',
            count($stages),
            $mode,
            $withCoverage ? 'on' : 'off',
            $context,
        ));

        $recorder->record(
            event: EventName::TestStarted,
            status: EventStatus::Ok,
            durationMs: 0,
            extras: [
                'context' => $context,
                'with_coverage' => $withCoverage,
            ],
        );

        $runner = new TestSuiteRunner(
            executor: $executor,
            filesystem: $filesystem,
            stages: $stages,
            reportDir: $config->reportDir !== '' ? $config->reportDir : null,
        );

        $runStartHrtime = hrtime(true);
        $result = $runner->run($mode, function (string $chunk): void {
            $this->getOutput()->write($chunk);
        });

        $totals = $this->aggregate($result->stages());
        $runDurationMs = (int) round((hrtime(true) - $runStartHrtime) / 1_000_000);

        $recorder->record(
            event: EventName::TestEnded,
            status: $result->hasFailures() ? EventStatus::Fail : EventStatus::Ok,
            durationMs: $runDurationMs,
            extras: [
                'pass_count' => $totals['passed'],
                'fail_count' => $totals['failed'],
                'skip_count' => $totals['skipped'],
                'coverage_percent' => -1, // unknown/unmeasured in this sprint
            ],
        );

        $this->renderSummary($result->stages(), $result->logFilePath());

        $exitCode = $result->hasFailures() ? self::FAILURE : self::SUCCESS;
        $this->emitCommandEnd($recorder, $exitCode, $commandStartHrtime);

        return $exitCode;
    }

    /**
     * @return list<StageConfig>
     */
    private function resolveStages(CodeguardConfig $config): array
    {
        $filter = (string) ($this->option('stage') ?? '');
        $enabled = $config->enabledStages();

        if ($filter === '') {
            return $enabled;
        }

        $matching = array_values(array_filter(
            $enabled,
            static fn (StageConfig $s): bool => $s->key === $filter,
        ));

        if ($matching === []) {
            $this->components->warn(sprintf('Stage "%s" is not enabled or not configured.', $filter));
        }

        return $matching;
    }

    private function resolveMode(): string
    {
        $raw = (string) ($this->option('mode') ?: 'fast-fail');

        return in_array($raw, self::ALLOWED_MODES, true) ? $raw : 'fast-fail';
    }

    private function resolveContext(): string
    {
        $raw = (string) ($this->option('context') ?: 'manual');

        return in_array($raw, self::ALLOWED_CONTEXTS, true) ? $raw : 'manual';
    }

    /**
     * @param  list<TestStageResult>  $stages
     * @return array{passed: int, failed: int, skipped: int}
     */
    private function aggregate(array $stages): array
    {
        $passed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($stages as $stage) {
            $passed += $stage->passed ?? 0;
            $failed += $stage->failed ?? 0;
            $skipped += $stage->skipped ?? 0;
        }

        return ['passed' => $passed, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * @param  list<TestStageResult>  $stages
     */
    private function renderSummary(array $stages, ?string $logPath): void
    {
        $this->line('');
        foreach ($stages as $stage) {
            $status = $stage->hasFailures() ? '<fg=red>FAIL</>' : '<fg=green>PASS</>';
            $this->components->twoColumnDetail(
                sprintf('%s %s', $status, $stage->label),
                sprintf('%dms', $stage->durationMs),
            );
        }

        if ($logPath !== null) {
            $this->components->warn(sprintf('Failure log: %s', $logPath));
        }
    }

    private function emitCommandEnd(Recorder $recorder, int $exitCode, int $startHrtime): void
    {
        $durationMs = (int) round((hrtime(true) - $startHrtime) / 1_000_000);

        $recorder->record(
            event: EventName::CommandEnd,
            status: $exitCode === 0 ? EventStatus::Ok : EventStatus::Fail,
            durationMs: $durationMs,
            extras: [
                'command' => 'test',
                'exit_code' => max(0, min(255, $exitCode)),
            ],
        );
    }
}
