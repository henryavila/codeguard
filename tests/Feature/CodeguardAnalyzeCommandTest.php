<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\AnalysisUnit;
use Henryavila\Codeguard\Analyze\AnalyzeRunner;
use Henryavila\Codeguard\Analyze\LlmClient;
use Henryavila\Codeguard\Telemetry\ConfigGate;
use Henryavila\Codeguard\Telemetry\FieldAllowlist;
use Henryavila\Codeguard\Telemetry\JsonlWriter;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Telemetry\Rotator;
use Henryavila\Codeguard\Tests\Support\FakeLlmClient;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| codeguard:analyze — feature tests
|--------------------------------------------------------------------------
|
| Runs the real command end-to-end with a FakeLlmClient (no network) and a
| temp telemetry path. Uses app()/Artisan rather than $this-> so the file
| stays PHPStan-clean without baseline entries.
|
*/

function analyzeTelemetryPath(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-analyze-feature-'.uniqid().'.jsonl';
}

function analyzeFixtureFile(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-analyze-fixture-'.uniqid();
    mkdir($dir, 0o755, true);
    $file = $dir.DIRECTORY_SEPARATOR.'Sample.php';
    file_put_contents($file, "<?php\n\nclass Sample\n{\n    public function handle(): void {}\n}\n");

    return $file;
}

function analyzeCleanup(string $fixtureFile, string $telemetryPath): void
{
    if (is_file($fixtureFile)) {
        unlink($fixtureFile);
    }
    $dir = dirname($fixtureFile);
    if (is_dir($dir)) {
        rmdir($dir);
    }
    if (is_file($telemetryPath)) {
        unlink($telemetryPath);
    }
}

function analyzeBind(string $telemetryPath, FakeLlmClient $fake): void
{
    app()->forgetInstance(Recorder::class);
    app()->singleton(Recorder::class, fn (): Recorder => new Recorder(
        gate: new ConfigGate(enabled: true),
        allowlist: new FieldAllowlist(strictMode: true),
        rotator: new Rotator,
        writer: new JsonlWriter,
        activePath: $telemetryPath,
    ));

    app()->forgetInstance(LlmClient::class);
    app()->singleton(LlmClient::class, fn (): FakeLlmClient => $fake);

    // Force the runner to rebuild with the rebound Recorder + LlmClient.
    app()->forgetInstance(AnalyzeRunner::class);
}

/**
 * @return Closure(AnalysisUnit): list<array<string, mixed>>
 */
function analyzeFindingHandler(string $severity): Closure
{
    return function (AnalysisUnit $unit) use ($severity): array {
        $key = $unit->patternKeys()[0] ?? 'no-god-object';

        return [[
            'pattern_key' => $key,
            'file' => $unit->file,
            'line' => 3,
            'message' => 'fixture violation',
            'severity' => $severity,
            'confidence' => 0.8,
        ]];
    };
}

/**
 * @return list<array<string, mixed>>
 */
function analyzeReadEvents(string $path): array
{
    if (! is_file($path)) {
        return [];
    }

    $events = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            $events[] = $decoded;
        }
    }

    return $events;
}

it('prints a warning finding and exits 0 under default fail-on=critical', function (): void {
    $telemetry = analyzeTelemetryPath();
    $file = analyzeFixtureFile();
    $fake = new FakeLlmClient(analyzeFindingHandler('warning'));
    analyzeBind($telemetry, $fake);

    try {
        $exit = Artisan::call('codeguard:analyze', ['--path' => $file, '--context' => 'ci']);

        expect($exit)->toBe(0)
            ->and(Artisan::output())->toContain('fixture violation')
            ->and($fake->calls)->toHaveCount(1);
    } finally {
        analyzeCleanup($file, $telemetry);
    }
});

it('exits 1 when a critical finding meets the default threshold', function (): void {
    $telemetry = analyzeTelemetryPath();
    $file = analyzeFixtureFile();
    $fake = new FakeLlmClient(analyzeFindingHandler('critical'));
    analyzeBind($telemetry, $fake);

    try {
        $exit = Artisan::call('codeguard:analyze', ['--path' => $file, '--context' => 'ci']);

        expect($exit)->toBe(1);
    } finally {
        analyzeCleanup($file, $telemetry);
    }
});

it('exits 0 with --fail-on=never even on a critical finding', function (): void {
    $telemetry = analyzeTelemetryPath();
    $file = analyzeFixtureFile();
    $fake = new FakeLlmClient(analyzeFindingHandler('critical'));
    analyzeBind($telemetry, $fake);

    try {
        $exit = Artisan::call('codeguard:analyze', ['--path' => $file, '--fail-on' => 'never']);

        expect($exit)->toBe(0);
    } finally {
        analyzeCleanup($file, $telemetry);
    }
});

it('emits command.start, analyze.ended, command.end and one LLM call per file', function (): void {
    $telemetry = analyzeTelemetryPath();
    $file = analyzeFixtureFile();
    $fake = new FakeLlmClient(analyzeFindingHandler('warning'));
    analyzeBind($telemetry, $fake);

    try {
        Artisan::call('codeguard:analyze', ['--path' => $file, '--context' => 'ci']);

        $events = analyzeReadEvents($telemetry);
        $names = array_map(static fn (array $event): mixed => $event['event'] ?? null, $events);

        expect($names[0] ?? null)->toBe('command.start')
            ->and(end($names))->toBe('command.end')
            ->and(in_array('analyze.ended', $names, true))->toBeTrue()
            ->and($fake->calls)->toHaveCount(1);
    } finally {
        analyzeCleanup($file, $telemetry);
    }
});

it('does not adjudicate or fake a clean repo when no driver is configured', function (): void {
    $telemetry = analyzeTelemetryPath();
    $file = analyzeFixtureFile();
    $fake = new FakeLlmClient(analyzeFindingHandler('critical'), configured: false);
    analyzeBind($telemetry, $fake);

    try {
        $exit = Artisan::call('codeguard:analyze', ['--path' => $file, '--context' => 'ci']);

        $events = analyzeReadEvents($telemetry);
        $analyzeEnded = array_values(array_filter(
            $events,
            static fn (array $event): bool => ($event['event'] ?? '') === 'analyze.ended',
        ));

        expect($exit)->toBe(0)
            ->and($fake->calls)->toHaveCount(0)
            ->and($analyzeEnded[0]['status'] ?? null)->toBe('skip');
    } finally {
        analyzeCleanup($file, $telemetry);
    }
});

it('reports a clean result and exits 0 when the driver returns no findings', function (): void {
    $telemetry = analyzeTelemetryPath();
    $file = analyzeFixtureFile();
    $fake = new FakeLlmClient(fn (AnalysisUnit $unit): array => []);
    analyzeBind($telemetry, $fake);

    try {
        $exit = Artisan::call('codeguard:analyze', ['--path' => $file, '--context' => 'ci']);

        $events = analyzeReadEvents($telemetry);
        $analyzeEnded = array_values(array_filter(
            $events,
            static fn (array $event): bool => ($event['event'] ?? '') === 'analyze.ended',
        ));

        expect($exit)->toBe(0)
            ->and($fake->calls)->toHaveCount(1)
            ->and($analyzeEnded[0]['status'] ?? null)->toBe('ok')
            ->and($analyzeEnded[0]['matches_count'] ?? null)->toBe(0);
    } finally {
        analyzeCleanup($file, $telemetry);
    }
});
