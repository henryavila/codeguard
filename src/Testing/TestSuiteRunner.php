<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Testing;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use SimpleXMLElement;

class TestSuiteRunner
{
    /**
     * @param  list<StageConfig>  $stages
     */
    public function __construct(
        private readonly CommandExecutor $executor,
        private readonly Filesystem $filesystem,
        private readonly array $stages,
        private readonly ?string $reportDir = null,
    ) {}

    public function run(string $mode = 'fast-fail', ?callable $writeOutput = null): TestRunResult
    {
        $results = [];
        $stageOutputBuffers = [];
        $runStartedAt = hrtime(true);

        foreach ($this->phases() as $phaseStages) {
            $phaseResults = $this->canRunAsync() && count($phaseStages) > 1
                ? $this->runParallel($phaseStages, $mode, $writeOutput, $stageOutputBuffers)
                : $this->runSequential($phaseStages, $mode, $writeOutput, $stageOutputBuffers);

            $results = array_merge($results, $phaseResults);

            if ($mode === 'fast-fail' && $this->phaseHasFailures($phaseResults)) {
                break;
            }
        }

        $logFilePath = $this->writeFailureLog($results, $stageOutputBuffers, $mode);

        return new TestRunResult($results, $this->elapsedMilliseconds($runStartedAt), $logFilePath);
    }

    // ─── Phase grouping ───────────────────────────────────────────────

    /**
     * Group stages into phases that run sequentially.
     * Stages within the same phase may run in parallel.
     *
     * @return list<list<StageConfig>>
     */
    private function phases(): array
    {
        $grouped = [];
        foreach ($this->stages as $stage) {
            $grouped[$stage->phase][] = $stage;
        }
        ksort($grouped);

        return array_values($grouped);
    }

    // ─── Sequential execution (fallback for basic CommandExecutor) ────

    /**
     * @param  list<StageConfig>  $stages
     * @param  array<string, string>  $stageOutputBuffers
     * @return list<TestStageResult>
     */
    private function runSequential(array $stages, string $mode, ?callable $writeOutput, array &$stageOutputBuffers): array
    {
        $results = [];

        foreach ($stages as $stage) {
            $results[] = $this->runSingleStage($stage, $mode, $writeOutput, $stageOutputBuffers);

            if ($mode === 'fast-fail' && end($results)->hasFailures()) {
                return $results;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $stageOutputBuffers
     */
    private function runSingleStage(StageConfig $stage, string $mode, ?callable $writeOutput, array &$stageOutputBuffers): TestStageResult
    {
        $this->announceStage($stage, $writeOutput);
        $stageStartedAt = hrtime(true);

        $command = $this->buildCommand($stage, $mode);
        $reportPath = $this->reportPath($stage);
        $this->ensureReportPath($reportPath);

        $buffer = '';
        $capturingCallback = $this->makeCapturingCallback($writeOutput, $buffer);

        $exitCode = $this->executor->run($command, $capturingCallback);
        $stageOutputBuffers[$stage->key] = $buffer;
        $durationMs = $this->elapsedMilliseconds($stageStartedAt);

        return $this->makeStageResult($stage, $command, $exitCode, $reportPath, $durationMs);
    }

    // ─── Parallel execution (requires AsyncCommandExecutor) ──────────

    /**
     * @param  list<StageConfig>  $stages
     * @param  array<string, string>  $stageOutputBuffers
     * @return list<TestStageResult>
     */
    private function runParallel(array $stages, string $mode, ?callable $writeOutput, array &$stageOutputBuffers): array
    {
        /** @var AsyncCommandExecutor $asyncExecutor */
        $asyncExecutor = $this->executor;
        $pending = [];

        foreach ($stages as $stage) {
            $command = $this->buildCommand($stage, $mode);
            $reportPath = $this->reportPath($stage);
            $this->ensureReportPath($reportPath);

            $pending[] = [
                'stage' => $stage,
                'command' => $command,
                'reportPath' => $reportPath,
                'process' => $asyncExecutor->start($command),
                'startedAt' => hrtime(true),
            ];
        }

        $results = [];
        foreach ($pending as $entry) {
            /** @var StageConfig $stage */
            $stage = $entry['stage'];
            /** @var list<string> $command */
            $command = $entry['command'];
            /** @var RunningCommand $process */
            $process = $entry['process'];
            /** @var int $startedAt */
            $startedAt = $entry['startedAt'];
            /** @var ?string $reportPath */
            $reportPath = $entry['reportPath'];

            $this->announceStage($stage, $writeOutput);

            $buffer = '';
            $capturingCallback = $this->makeCapturingCallback($writeOutput, $buffer);
            $exitCode = $process->wait($capturingCallback);
            $stageOutputBuffers[$stage->key] = $buffer;

            $durationMs = $this->elapsedMilliseconds($startedAt);
            $results[] = $this->makeStageResult($stage, $command, $exitCode, $reportPath, $durationMs);
        }

        return $results;
    }

    // ─── Output capture & failure log ───────────────────────────────

    private function makeCapturingCallback(?callable $writeOutput, string &$buffer): callable
    {
        return function (string $chunk) use ($writeOutput, &$buffer): void {
            $buffer .= $chunk;

            if ($writeOutput !== null) {
                $writeOutput($chunk);
            }
        };
    }

    /**
     * Write a log file containing only the output of failed stages.
     * Returns the log path if any failures exist, null otherwise.
     *
     * @param  list<TestStageResult>  $results
     * @param  array<string, string>  $stageOutputBuffers
     */
    private function writeFailureLog(array $results, array $stageOutputBuffers, string $mode): ?string
    {
        $failedStages = array_filter($results, static fn (TestStageResult $r): bool => $r->hasFailures());

        if ($failedStages === []) {
            return null;
        }

        $dir = $this->reportDirectory();
        $this->filesystem->ensureDirectoryExists($dir);

        $path = sprintf('%s/test-failures.log', $dir);
        $lines = [sprintf("Test run: %s | Mode: %s\n", date('Y-m-d H:i:s'), $mode)];

        foreach ($failedStages as $stage) {
            $lines[] = sprintf(
                "\n%s\n━━ %s (exit code %d) ━━\n%s\n",
                str_repeat('=', 60),
                $stage->label,
                $stage->exitCode,
                str_repeat('=', 60),
            );
            $lines[] = $stageOutputBuffers[$stage->key] ?? '(no output captured)';
        }

        $this->filesystem->put($path, implode('', $lines));

        return $path;
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function canRunAsync(): bool
    {
        return $this->executor instanceof AsyncCommandExecutor;
    }

    /**
     * @param  list<TestStageResult>  $phaseResults
     */
    private function phaseHasFailures(array $phaseResults): bool
    {
        foreach ($phaseResults as $result) {
            if ($result->hasFailures()) {
                return true;
            }
        }

        return false;
    }

    private function ensureReportPath(?string $reportPath): void
    {
        if ($reportPath === null) {
            return;
        }

        $this->filesystem->ensureDirectoryExists(dirname($reportPath));
        if ($this->filesystem->exists($reportPath)) {
            $this->filesystem->delete($reportPath);
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function makeStageResult(StageConfig $stage, array $command, int $exitCode, ?string $reportPath, int $durationMs): TestStageResult
    {
        if ($reportPath === null || $stage->reportType === null) {
            return new TestStageResult(
                key: $stage->key,
                label: $stage->label,
                command: $command,
                exitCode: $exitCode,
                passed: null,
                failed: null,
                skipped: null,
                failedTests: $exitCode === 0 ? [] : [sprintf('%s exited with code %d', $stage->label, $exitCode)],
                durationMs: $durationMs,
            );
        }

        $parsedReport = match ($stage->reportType) {
            'vitest-json' => $this->parseVitestJson($reportPath),
            'junit' => $this->parseJunit($reportPath),
            default => throw new RuntimeException(sprintf('Unsupported report type: %s', $stage->reportType)),
        };

        return new TestStageResult(
            key: $stage->key,
            label: $stage->label,
            command: $command,
            exitCode: $exitCode,
            passed: $parsedReport['passed'],
            failed: $parsedReport['failed'],
            skipped: $parsedReport['skipped'],
            failedTests: $parsedReport['failedTests'],
            durationMs: $durationMs,
        );
    }

    /**
     * @return list<string>
     */
    private function buildCommand(StageConfig $stage, string $mode): array
    {
        $command = $stage->command;

        if ($mode === 'fast-fail' && $stage->fastFailArguments !== []) {
            $command = [...$command, ...$stage->fastFailArguments];
        }

        $reportPath = $this->reportPath($stage);
        if ($reportPath !== null && $stage->reportArgPrefix !== null) {
            $command[] = sprintf('%s%s', $stage->reportArgPrefix, $reportPath);
        }

        return $command;
    }

    private function reportPath(StageConfig $stage): ?string
    {
        if ($stage->reportFile === null) {
            return null;
        }

        return sprintf('%s/%s', $this->reportDirectory(), $stage->reportFile);
    }

    private function reportDirectory(): string
    {
        return $this->reportDir ?? storage_path('framework/testing/test-reports');
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function announceStage(StageConfig $stage, ?callable $writeOutput): void
    {
        if ($writeOutput === null) {
            return;
        }

        $writeOutput(sprintf("\n━━ %s ━━\n", $stage->label));
    }

    /**
     * @return array{passed: int, failed: int, skipped: int, failedTests: list<string>}
     */
    private function parseVitestJson(string $path): array
    {
        if (! $this->filesystem->exists($path)) {
            return [
                'passed' => 0,
                'failed' => 1,
                'skipped' => 0,
                'failedTests' => [sprintf('Vitest report file not found: %s', $path)],
            ];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->filesystem->get($path), true, 512, JSON_THROW_ON_ERROR);
        $failedTests = [];

        /** @var list<array<string, mixed>> $testResults */
        $testResults = $decoded['testResults'] ?? [];

        foreach ($testResults as $testResult) {
            /** @var list<array<string, mixed>> $assertionResults */
            $assertionResults = $testResult['assertionResults'] ?? [];

            foreach ($assertionResults as $assertionResult) {
                $status = $assertionResult['status'] ?? null;

                if ($status === 'failed' || $status === 'fail') {
                    $failedTests[] = (string) ($assertionResult['fullName']
                        ?? $assertionResult['title']
                        ?? $testResult['name']
                        ?? 'Unknown test');
                }
            }
        }

        return [
            'passed' => (int) ($decoded['numPassedTests'] ?? 0),
            'failed' => (int) ($decoded['numFailedTests'] ?? 0),
            'skipped' => (int) (($decoded['numPendingTests'] ?? 0) + ($decoded['numTodoTests'] ?? 0)),
            'failedTests' => $failedTests,
        ];
    }

    /**
     * @return array{passed: int, failed: int, skipped: int, failedTests: list<string>}
     */
    private function parseJunit(string $path): array
    {
        if (! $this->filesystem->exists($path)) {
            return [
                'passed' => 0,
                'failed' => 1,
                'skipped' => 0,
                'failedTests' => [sprintf('JUnit report file not found: %s', $path)],
            ];
        }

        $xml = new SimpleXMLElement($this->filesystem->get($path));
        $failedTests = [];
        $testCases = $xml->xpath('//testcase') ?: [];
        $tests = count($testCases);
        $failed = 0;
        $skipped = 0;

        foreach ($testCases as $testCase) {
            $hasFailure = isset($testCase->failure[0]) || isset($testCase->error[0]);
            $isSkipped = isset($testCase->skipped[0]);

            if ($isSkipped) {
                $skipped++;
            }

            if (! $hasFailure) {
                continue;
            }

            $failed++;
            $class = (string) ($testCase['class'] ?? '');
            $name = (string) ($testCase['name'] ?? '');

            $failedTests[] = $class === ''
                ? $name
                : sprintf('%s::%s', $class, $name);
        }

        $passed = max(0, $tests - $failed - $skipped);

        return [
            'passed' => $passed,
            'failed' => $failed,
            'skipped' => $skipped,
            'failedTests' => $failedTests,
        ];
    }
}
