<?php

declare(strict_types=1);

use Henryavila\Codeguard\Testing\StageConfig;
use Henryavila\Codeguard\Testing\TestSuiteRunner;
use Henryavila\Codeguard\Tests\Support\FakeCommandExecutor;
use Illuminate\Filesystem\Filesystem;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function tempReportDir(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-runner-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    return $dir;
}

function runnerStage(
    string $key,
    int $phase = 1,
    array $command = ['echo', 'run'],
    ?string $reportType = null,
    ?string $reportFile = null,
    array $fastFailArguments = [],
): StageConfig {
    return new StageConfig(
        key: $key,
        enabled: true,
        label: ucfirst($key),
        phase: $phase,
        description: '',
        command: $command,
        env: [],
        reportType: $reportType,
        reportFile: $reportFile,
        reportArgPrefix: $reportFile !== null ? '--log-junit=' : null,
        fastFailArguments: $fastFailArguments,
    );
}

/*
|--------------------------------------------------------------------------
| Tests
|--------------------------------------------------------------------------
*/

it('runs a single stage sequentially and returns a passing result', function (): void {
    $executor = new FakeCommandExecutor(fn () => [0, '']);
    $runner = new TestSuiteRunner(
        executor: $executor,
        filesystem: new Filesystem,
        stages: [runnerStage('unit')],
        reportDir: tempReportDir(),
    );

    $result = $runner->run('report');

    expect($result->hasFailures())->toBeFalse()
        ->and($result->stages())->toHaveCount(1)
        ->and($result->stage('unit')?->exitCode)->toBe(0);
});

it('aborts next phase when prior phase fails in fast-fail mode (sequential across phases)', function (): void {
    $executor = new FakeCommandExecutor(fn (array $cmd) => str_contains($cmd[1] ?? '', 'fail') ? [1, ''] : [0, '']);
    $runner = new TestSuiteRunner(
        executor: $executor,
        filesystem: new Filesystem,
        stages: [
            runnerStage('first', phase: 1, command: ['echo', 'fail']),
            runnerStage('second', phase: 2, command: ['echo', 'ok']),
        ],
        reportDir: tempReportDir(),
    );

    $result = $runner->run('fast-fail');

    expect($result->stages())->toHaveCount(1)
        ->and($result->hasFailures())->toBeTrue()
        ->and($executor->executedCommands)->toHaveCount(1);
});

it('runs all stages in report mode even if one fails', function (): void {
    $executor = new FakeCommandExecutor(fn (array $cmd) => str_contains($cmd[1] ?? '', 'fail') ? [1, ''] : [0, '']);
    $runner = new TestSuiteRunner(
        executor: $executor,
        filesystem: new Filesystem,
        stages: [
            runnerStage('first', command: ['echo', 'fail']),
            runnerStage('second', command: ['echo', 'ok']),
        ],
        reportDir: tempReportDir(),
    );

    $result = $runner->run('report');

    expect($result->stages())->toHaveCount(2)
        ->and($result->stage('first')?->hasFailures())->toBeTrue()
        ->and($result->stage('second')?->hasFailures())->toBeFalse();
});

it('groups stages by phase and runs each phase sequentially', function (): void {
    $executor = new FakeCommandExecutor(fn () => [0, '']);
    $runner = new TestSuiteRunner(
        executor: $executor,
        filesystem: new Filesystem,
        stages: [
            runnerStage('a', phase: 2, command: ['echo', 'a']),
            runnerStage('b', phase: 1, command: ['echo', 'b']),
            runnerStage('c', phase: 2, command: ['echo', 'c']),
        ],
        reportDir: tempReportDir(),
    );

    $runner->run('report');

    // Phase 1 must complete before phase 2 stages kick off — so 'b' runs first
    expect($executor->executedCommands[0][1])->toBe('b');
});

it('aborts subsequent phases when a prior phase fails in fast-fail mode', function (): void {
    $executor = new FakeCommandExecutor(fn (array $cmd) => str_contains($cmd[1] ?? '', 'fail') ? [1, ''] : [0, '']);
    $runner = new TestSuiteRunner(
        executor: $executor,
        filesystem: new Filesystem,
        stages: [
            runnerStage('p1', phase: 1, command: ['echo', 'fail']),
            runnerStage('p2a', phase: 2, command: ['echo', 'ok']),
            runnerStage('p2b', phase: 2, command: ['echo', 'ok']),
        ],
        reportDir: tempReportDir(),
    );

    $result = $runner->run('fast-fail');

    expect($result->stages())->toHaveCount(1)
        ->and($result->stage('p2a'))->toBeNull()
        ->and($result->stage('p2b'))->toBeNull();
});

it('writes a failure log only when at least one stage failed', function (): void {
    $reportDir = tempReportDir();
    $executor = new FakeCommandExecutor(fn (array $cmd) => str_contains($cmd[1] ?? '', 'fail') ? [1, 'boom output'] : [0, '']);
    $runner = new TestSuiteRunner(
        executor: $executor,
        filesystem: new Filesystem,
        stages: [
            runnerStage('first', command: ['echo', 'fail']),
            runnerStage('second', command: ['echo', 'ok']),
        ],
        reportDir: $reportDir,
    );

    $result = $runner->run('report');

    expect($result->logFilePath())->not->toBeNull()
        ->and(file_exists($result->logFilePath()))->toBeTrue()
        ->and(file_get_contents($result->logFilePath()))->toContain('boom output');
});

it('omits failure log entirely when all stages pass', function (): void {
    $executor = new FakeCommandExecutor(fn () => [0, '']);
    $runner = new TestSuiteRunner(
        executor: $executor,
        filesystem: new Filesystem,
        stages: [runnerStage('ok', command: ['echo', 'ok'])],
        reportDir: tempReportDir(),
    );

    $result = $runner->run('report');

    expect($result->logFilePath())->toBeNull();
});

it('parses junit report and populates passed/failed counts', function (): void {
    $reportDir = tempReportDir();
    $junitPath = $reportDir.'/unit.xml';

    $executor = new FakeCommandExecutor(function (array $_cmd) use ($junitPath): array {
        // Simulate the test runner producing the junit file
        file_put_contents($junitPath, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="Unit" tests="3" assertions="3">
    <testcase class="Tests\Unit\FooTest" name="it passes"/>
    <testcase class="Tests\Unit\FooTest" name="it also passes"/>
    <testcase class="Tests\Unit\FooTest" name="it fails">
      <failure type="AssertionError">boom</failure>
    </testcase>
  </testsuite>
</testsuites>
XML);

        return [1, ''];
    });

    $runner = new TestSuiteRunner(
        executor: $executor,
        filesystem: new Filesystem,
        stages: [runnerStage(
            'unit',
            command: ['pest'],
            reportType: 'junit',
            reportFile: 'unit.xml',
        )],
        reportDir: $reportDir,
    );

    $result = $runner->run('report');
    $stage = $result->stage('unit');

    expect($stage)->not->toBeNull()
        ->and($stage->passed)->toBe(2)
        ->and($stage->failed)->toBe(1)
        ->and($stage->failedTests)->toBe(['Tests\\Unit\\FooTest::it fails']);
});

it('parses vitest-json report', function (): void {
    $reportDir = tempReportDir();
    $reportPath = $reportDir.'/frontend.json';

    $executor = new FakeCommandExecutor(function (array $_cmd) use ($reportPath): array {
        file_put_contents($reportPath, json_encode([
            'numPassedTests' => 5,
            'numFailedTests' => 2,
            'numPendingTests' => 1,
            'numTodoTests' => 0,
            'testResults' => [
                [
                    'name' => 'components/Button.test.ts',
                    'assertionResults' => [
                        ['status' => 'passed', 'title' => 'renders'],
                        ['status' => 'failed', 'fullName' => 'Button > handles click'],
                    ],
                ],
            ],
        ]));

        return [1, ''];
    });

    $runner = new TestSuiteRunner(
        executor: $executor,
        filesystem: new Filesystem,
        stages: [runnerStage(
            'frontend',
            command: ['vitest'],
            reportType: 'vitest-json',
            reportFile: 'frontend.json',
        )],
        reportDir: $reportDir,
    );

    $result = $runner->run('report');
    $stage = $result->stage('frontend');

    expect($stage)->not->toBeNull()
        ->and($stage->passed)->toBe(5)
        ->and($stage->failed)->toBe(2)
        ->and($stage->skipped)->toBe(1)
        ->and($stage->failedTests)->toBe(['Button > handles click']);
});

it('appends fastFailArguments to command when mode=fast-fail', function (): void {
    $executor = new FakeCommandExecutor(fn () => [0, '']);
    $runner = new TestSuiteRunner(
        executor: $executor,
        filesystem: new Filesystem,
        stages: [runnerStage('unit', command: ['pest'], fastFailArguments: ['--bail'])],
        reportDir: tempReportDir(),
    );

    $runner->run('fast-fail');

    expect($executor->executedCommands[0])->toBe(['pest', '--bail']);
});

it('does not append fastFailArguments in report mode', function (): void {
    $executor = new FakeCommandExecutor(fn () => [0, '']);
    $runner = new TestSuiteRunner(
        executor: $executor,
        filesystem: new Filesystem,
        stages: [runnerStage('unit', command: ['pest'], fastFailArguments: ['--bail'])],
        reportDir: tempReportDir(),
    );

    $runner->run('report');

    expect($executor->executedCommands[0])->toBe(['pest']);
});

it('appends reportArgPrefix+path when stage has reportFile', function (): void {
    $reportDir = tempReportDir();
    $executor = new FakeCommandExecutor(fn () => [0, '']);
    $runner = new TestSuiteRunner(
        executor: $executor,
        filesystem: new Filesystem,
        stages: [runnerStage(
            'unit',
            command: ['pest'],
            reportType: 'junit',
            reportFile: 'unit.xml',
        )],
        reportDir: $reportDir,
    );

    $runner->run('report');

    expect($executor->executedCommands[0])->toBe(['pest', '--log-junit='.$reportDir.'/unit.xml']);
});

it('streams output through writeOutput callback when provided', function (): void {
    $captured = '';
    $executor = new FakeCommandExecutor(fn () => [0, 'hello world']);
    $runner = new TestSuiteRunner(
        executor: $executor,
        filesystem: new Filesystem,
        stages: [runnerStage('unit', command: ['echo'])],
        reportDir: tempReportDir(),
    );

    $runner->run('report', function (string $chunk) use (&$captured): void {
        $captured .= $chunk;
    });

    expect($captured)->toContain('hello world')
        ->and($captured)->toContain('━━ Unit ━━');
});
