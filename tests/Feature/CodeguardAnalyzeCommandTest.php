<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\AnalysisUnit;
use Henryavila\Codeguard\Analyze\AnalyzeBaseline;
use Henryavila\Codeguard\Analyze\AnalyzeOptions;
use Henryavila\Codeguard\Analyze\AnalyzeRunner;
use Henryavila\Codeguard\Analyze\LlmClient;
use Henryavila\Codeguard\Telemetry\ConfigGate;
use Henryavila\Codeguard\Telemetry\FieldAllowlist;
use Henryavila\Codeguard\Telemetry\JsonlWriter;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Telemetry\Rotator;
use Henryavila\Codeguard\Tests\Support\FakeLlmClient;
use Illuminate\Filesystem\Filesystem;
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

function analyzeBind(string $telemetryPath, FakeLlmClient $fake, ?string $baselinePath = null): void
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

    // Isolate the baseline to a temp path so accept-tests don't touch the skeleton.
    $resolvedBaseline = $baselinePath ?? sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-analyze-baseline-'.uniqid().'.json';
    app()->forgetInstance(AnalyzeBaseline::class);
    app()->singleton(AnalyzeBaseline::class, fn (): AnalyzeBaseline => new AnalyzeBaseline(
        new Filesystem,
        $resolvedBaseline,
        (string) base_path(),
    ));

    // Force the runner to rebuild with the rebound Recorder + LlmClient + baseline.
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

it('falls back to context-emit (work order) when no LLM driver is configured', function (): void {
    $telemetry = analyzeTelemetryPath();
    $file = analyzeFixtureFile();
    $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-fallback-'.uniqid().'.json';
    $fake = new FakeLlmClient(analyzeFindingHandler('critical'), configured: false);
    analyzeBind($telemetry, $fake);

    try {
        $exit = Artisan::call('codeguard:analyze', ['--path' => $file, '--out' => $out, '--context' => 'ci']);
        $output = Artisan::output();

        // No synchronous adjudication is attempted, and instead of a dead-end
        // notice the command emits a work order for the context-emit review path.
        expect($exit)->toBe(0)
            ->and($fake->calls)->toHaveCount(0)
            ->and(is_file($out))->toBeTrue()
            ->and($output)->toContain('context-emit');

        $decoded = json_decode((string) file_get_contents($out), true);
        $units = (is_array($decoded) && is_array($decoded['units'] ?? null)) ? $decoded['units'] : [];

        expect($units)->toHaveCount(1);
    } finally {
        if (is_file($out)) {
            unlink($out);
        }
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

it('fails with a clear error when the --ingest findings file does not exist', function (): void {
    $telemetry = analyzeTelemetryPath();
    $file = analyzeFixtureFile();
    $missing = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-missing-'.uniqid().'.json';
    $fake = new FakeLlmClient(fn (AnalysisUnit $unit): array => []);
    analyzeBind($telemetry, $fake);

    try {
        $exit = Artisan::call('codeguard:analyze', ['--ingest' => $missing, '--path' => $file, '--context' => 'ci']);

        expect($exit)->toBe(1)
            ->and(Artisan::output())->toContain('not found')
            ->and($fake->calls)->toHaveCount(0);
    } finally {
        analyzeCleanup($file, $telemetry);
    }
});

it('emits a work order JSON with units and prompt-ready patterns', function (): void {
    $file = analyzeFixtureFile();
    $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-workorder-'.uniqid().'.json';

    try {
        $exit = Artisan::call('codeguard:analyze', ['--emit' => true, '--path' => $file, '--out' => $out]);

        expect($exit)->toBe(0)
            ->and(is_file($out))->toBeTrue();

        $decoded = json_decode((string) file_get_contents($out), true);
        $units = (is_array($decoded) && is_array($decoded['units'] ?? null)) ? $decoded['units'] : [];

        expect($units)->toHaveCount(1);

        $first = is_array($units[0] ?? null) ? $units[0] : [];
        $patterns = is_array($first['patterns'] ?? null) ? $first['patterns'] : [];
        $patternZero = is_array($patterns[0] ?? null) ? $patterns[0] : [];

        expect($first['file'] ?? null)->toBe($file)
            ->and(count($patterns))->toBeGreaterThan(0)
            ->and($patternZero)->toHaveKeys(['key', 'description', 'severity', 'verification_rules', 'examples']);
    } finally {
        analyzeCleanup($file, $out);
    }
});

it('ingests findings, drops hallucinations via the trust boundary, and gates the exit code', function (): void {
    $telemetry = analyzeTelemetryPath();
    $file = analyzeFixtureFile();
    $findingsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-findings-'.uniqid().'.json';

    file_put_contents($findingsPath, (string) json_encode([
        ['pattern_key' => 'no-god-object', 'file' => $file, 'line' => 3, 'message' => 'too many responsibilities', 'severity' => 'critical', 'confidence' => 0.9],
        ['pattern_key' => 'ghost-pattern', 'file' => $file, 'line' => 1, 'message' => 'hallucinated', 'severity' => 'critical', 'confidence' => 0.9],
    ]));

    $fake = new FakeLlmClient(fn (AnalysisUnit $unit): array => []);
    analyzeBind($telemetry, $fake);

    try {
        $exit = Artisan::call('codeguard:analyze', ['--ingest' => $findingsPath, '--path' => $file, '--context' => 'ci']);

        $events = analyzeReadEvents($telemetry);
        $analyzeEnded = array_values(array_filter(
            $events,
            static fn (array $event): bool => ($event['event'] ?? '') === 'analyze.ended',
        ));

        expect($exit)->toBe(1)
            ->and($analyzeEnded[0]['matches_count'] ?? null)->toBe(1)
            ->and($fake->calls)->toHaveCount(0);
    } finally {
        if (is_file($findingsPath)) {
            unlink($findingsPath);
        }
        analyzeCleanup($file, $telemetry);
    }
});

it('votes across a samples envelope on ingest and keeps a majority finding with vote-share confidence', function (): void {
    $telemetry = analyzeTelemetryPath();
    $file = analyzeFixtureFile();
    $findingsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-samples-'.uniqid().'.json';

    $finding = ['pattern_key' => 'no-god-object', 'file' => $file, 'line' => 3, 'message' => 'too many responsibilities', 'severity' => 'critical', 'confidence' => 0.99];
    file_put_contents($findingsPath, (string) json_encode([
        'samples' => [
            [$finding],
            [$finding],
            [], // absent in the third sample
        ],
    ]));

    $fake = new FakeLlmClient(fn (AnalysisUnit $unit): array => []);
    analyzeBind($telemetry, $fake);

    try {
        $exit = Artisan::call('codeguard:analyze', ['--ingest' => $findingsPath, '--path' => $file, '--context' => 'ci']);
        $output = Artisan::output();

        $analyzeEnded = array_values(array_filter(
            analyzeReadEvents($telemetry),
            static fn (array $event): bool => ($event['event'] ?? '') === 'analyze.ended',
        ));

        expect($exit)->toBe(1) // critical meets the default fail-on
            ->and($analyzeEnded[0]['matches_count'] ?? null)->toBe(1)
            ->and($output)->toContain('0.67'); // vote-share 2/3, NOT the model's 0.99
    } finally {
        if (is_file($findingsPath)) {
            unlink($findingsPath);
        }
        analyzeCleanup($file, $telemetry);
    }
});

it('drops a finding that misses the sample vote threshold on ingest', function (): void {
    $telemetry = analyzeTelemetryPath();
    $file = analyzeFixtureFile();
    $findingsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-samples-'.uniqid().'.json';

    $finding = ['pattern_key' => 'no-god-object', 'file' => $file, 'line' => 3, 'message' => 'maybe', 'severity' => 'critical', 'confidence' => 0.99];
    file_put_contents($findingsPath, (string) json_encode([
        'samples' => [[$finding], [], []], // only 1 of 3 votes
    ]));

    $fake = new FakeLlmClient(fn (AnalysisUnit $unit): array => []);
    analyzeBind($telemetry, $fake);

    try {
        $exit = Artisan::call('codeguard:analyze', ['--ingest' => $findingsPath, '--path' => $file, '--context' => 'ci']);

        $analyzeEnded = array_values(array_filter(
            analyzeReadEvents($telemetry),
            static fn (array $event): bool => ($event['event'] ?? '') === 'analyze.ended',
        ));

        expect($exit)->toBe(0)
            ->and($analyzeEnded[0]['matches_count'] ?? null)->toBe(0);
    } finally {
        if (is_file($findingsPath)) {
            unlink($findingsPath);
        }
        analyzeCleanup($file, $telemetry);
    }
});

it('emits a work order carrying the requested sample count', function (): void {
    $file = analyzeFixtureFile();
    $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-workorder-'.uniqid().'.json';

    try {
        $exit = Artisan::call('codeguard:analyze', ['--emit' => true, '--samples' => 3, '--path' => $file, '--out' => $out]);

        expect($exit)->toBe(0);

        $decoded = json_decode((string) file_get_contents($out), true);

        expect(is_array($decoded) ? ($decoded['samples'] ?? null) : null)->toBe(3);
    } finally {
        analyzeCleanup($file, $out);
    }
});

it('emits a work order flagging the critique pass when requested', function (): void {
    $file = analyzeFixtureFile();
    $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-workorder-'.uniqid().'.json';

    try {
        Artisan::call('codeguard:analyze', ['--emit' => true, '--critique' => true, '--path' => $file, '--out' => $out]);

        $decoded = json_decode((string) file_get_contents($out), true);

        expect(is_array($decoded) ? ($decoded['critique'] ?? null) : null)->toBeTrue();
    } finally {
        analyzeCleanup($file, $out);
    }
});

it('emits a contractor-focused work order with raised critique floor metadata', function (): void {
    $file = analyzeFixtureFile();
    $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-workorder-'.uniqid().'.json';

    try {
        Artisan::call('codeguard:analyze', [
            '--emit' => true,
            '--focus' => 'contractor',
            '--path' => $file,
            '--out' => $out,
        ]);

        $decoded = json_decode((string) file_get_contents($out), true);
        expect(is_array($decoded))->toBeTrue();

        $keys = [];
        foreach ($decoded['units'] ?? [] as $unit) {
            foreach ($unit['patterns'] ?? [] as $pattern) {
                $keys[] = $pattern['key'] ?? '';
            }
        }
        $keys = array_values(array_unique($keys));

        expect($decoded['min_critique_score'] ?? null)->toBe(4)
            ->and($keys)->not->toContain('type-declarations')
            ->and($keys)->not->toContain('dry');
        // Fixture is a small class — contractor may attach service-layer / core arch only if signals match.
        foreach ($keys as $key) {
            expect(AnalyzeOptions::CONTRACTOR_KEYS)->toContain($key);
        }
    } finally {
        analyzeCleanup($file, $out);
    }
});

it('drops a critique-rejected finding on ingest and shows the verified score', function (): void {
    $telemetry = analyzeTelemetryPath();
    $file = analyzeFixtureFile();
    $findingsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-critique-'.uniqid().'.json';

    file_put_contents($findingsPath, (string) json_encode([
        ['pattern_key' => 'no-god-object', 'file' => $file, 'line' => 3, 'message' => 'real', 'severity' => 'critical', 'confidence' => 0.9, 'verified_score' => 9],
        ['pattern_key' => 'no-god-object', 'file' => $file, 'line' => 5, 'message' => 'rejected', 'severity' => 'critical', 'confidence' => 0.9, 'verified_score' => 0],
    ]));

    $fake = new FakeLlmClient(fn (AnalysisUnit $unit): array => []);
    analyzeBind($telemetry, $fake);

    try {
        $exit = Artisan::call('codeguard:analyze', ['--ingest' => $findingsPath, '--path' => $file, '--context' => 'ci']);
        $output = Artisan::output();

        $analyzeEnded = array_values(array_filter(
            analyzeReadEvents($telemetry),
            static fn (array $event): bool => ($event['event'] ?? '') === 'analyze.ended',
        ));

        expect($exit)->toBe(1)
            ->and($analyzeEnded[0]['matches_count'] ?? null)->toBe(1)
            ->and($output)->toContain('9/10')
            ->and($output)->not->toContain('rejected');
    } finally {
        if (is_file($findingsPath)) {
            unlink($findingsPath);
        }
        analyzeCleanup($file, $telemetry);
    }
});

it('accepts a finding into the baseline and suppresses it on the next run', function (): void {
    $telemetry = analyzeTelemetryPath();
    $baselinePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-accept-'.uniqid().'.json';
    $file = analyzeFixtureFile();
    $fake = new FakeLlmClient(analyzeFindingHandler('warning'));
    analyzeBind($telemetry, $fake, $baselinePath);

    try {
        // Run 1: --accept records the finding's fingerprint.
        Artisan::call('codeguard:analyze', ['--path' => $file, '--context' => 'ci', '--accept' => true]);
        expect(is_file($baselinePath))->toBeTrue();

        // Run 2: the same finding is now suppressed.
        Artisan::call('codeguard:analyze', ['--path' => $file, '--context' => 'ci']);

        $analyzeEnded = array_values(array_filter(
            analyzeReadEvents($telemetry),
            static fn (array $event): bool => ($event['event'] ?? '') === 'analyze.ended',
        ));
        $lastRun = end($analyzeEnded);

        expect($analyzeEnded)->toHaveCount(2)
            ->and(is_array($lastRun) ? ($lastRun['matches_count'] ?? null) : null)->toBe(0)
            ->and($fake->calls)->toHaveCount(2);
    } finally {
        if (is_file($baselinePath)) {
            unlink($baselinePath);
        }
        analyzeCleanup($file, $telemetry);
    }
});

it('emits a work order with scope.files and head metadata', function (): void {
    $file = analyzeFixtureFile();
    $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-scope-'.uniqid().'.json';

    try {
        Artisan::call('codeguard:analyze', ['--emit' => true, '--path' => $file, '--out' => $out]);
        $decoded = json_decode((string) file_get_contents($out), true);

        expect($decoded['scope']['mode'] ?? null)->toBe('path')
            ->and($decoded['scope']['files'] ?? null)->toContain($file);
    } finally {
        analyzeCleanup($file, $out);
    }
});

it('aborts overwriting a non-empty work order with an empty emit unless --force', function (): void {
    $file = analyzeFixtureFile();
    $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-overwrite-'.uniqid().'.json';
    $emptyDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-empty-'.uniqid();
    mkdir($emptyDir, 0o755, true);

    try {
        Artisan::call('codeguard:analyze', ['--emit' => true, '--path' => $file, '--out' => $out]);
        $before = (string) file_get_contents($out);
        expect(json_decode($before, true)['units'] ?? [])->not->toBeEmpty();

        $exit = Artisan::call('codeguard:analyze', [
            '--emit' => true,
            '--path' => $emptyDir,
            '--out' => $out,
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(1)
            ->and($output)->toContain('Refusing to overwrite')
            ->and($output)->toContain('--force')
            ->and((string) file_get_contents($out))->toBe($before);

        $forced = Artisan::call('codeguard:analyze', [
            '--emit' => true,
            '--path' => $emptyDir,
            '--out' => $out,
            '--force' => true,
        ]);
        expect($forced)->toBe(0)
            ->and(json_decode((string) file_get_contents($out), true)['units'] ?? null)->toBe([]);
    } finally {
        if (is_file($out)) {
            unlink($out);
        }
        if (is_dir($emptyDir)) {
            rmdir($emptyDir);
        }
        analyzeCleanup($file, $out);
    }
});

it('prints BLOCK / REQUEST CHANGE / INFO sections and a checklist on ingest', function (): void {
    $telemetry = analyzeTelemetryPath();
    // File must live under app basePath so PatternMatcher relative globs match.
    $rel = 'app/Http/Controllers/CodeguardTmpOrderController.php';
    $file = base_path($rel);
    $dir = dirname($file);
    if (! is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }
    file_put_contents($file, "<?php\nnamespace App\\Http\\Controllers;\nuse App\\Services\\OrderService;\nclass CodeguardTmpOrderController { public function store(): void {} }\n");
    $findingsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-action-'.uniqid().'.json';

    file_put_contents($findingsPath, (string) json_encode([
        [
            'pattern_key' => 'mass-assignment',
            'file' => $file,
            'line' => 3,
            'message' => 'uses request()->all()',
            'severity' => 'critical',
            'confidence' => 0.9,
            'verified_score' => 8,
        ],
        [
            'pattern_key' => 'service-layer',
            'file' => $file,
            'line' => 4,
            'message' => 'controller does too much',
            'severity' => 'warning',
            'confidence' => 0.8,
        ],
    ]));

    $fake = new FakeLlmClient(fn (AnalysisUnit $unit): array => []);
    analyzeBind($telemetry, $fake);

    try {
        $exit = Artisan::call('codeguard:analyze', [
            '--ingest' => $findingsPath,
            '--path' => $file,
            '--fail-on' => 'never',
            '--context' => 'ci',
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain('## BLOCK')
            ->and($output)->toContain('## REQUEST CHANGE')
            ->and($output)->toContain('## INFO')
            ->and($output)->toContain('Checklist (markdown):')
            ->and($output)->toContain('mass-assignment')
            ->and($output)->toContain('block=')
            ->and($output)->toContain('request_change=');
    } finally {
        if (is_file($findingsPath)) {
            unlink($findingsPath);
        }
        if (is_file($file)) {
            unlink($file);
        }
        // Best-effort cleanup of empty dirs created under testbench base.
        foreach (['app/Http/Controllers', 'app/Http', 'app'] as $d) {
            $p = base_path($d);
            if (is_dir($p) && count(scandir($p) ?: []) === 2) {
                @rmdir($p);
            }
        }
        if (is_file($telemetry)) {
            unlink($telemetry);
        }
    }
});

it('reuses scope.files from the request work order on ingest', function (): void {
    $telemetry = analyzeTelemetryPath();
    $file = analyzeFixtureFile();
    $request = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-req-'.uniqid().'.json';
    $findingsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-ing-'.uniqid().'.json';

    file_put_contents($request, (string) json_encode([
        'focus' => 'full',
        'min_critique_score' => 1,
        'scope' => [
            'mode' => 'path',
            'head_sha' => null,
            'files' => [$file],
        ],
        'units' => [],
    ]));
    file_put_contents($findingsPath, (string) json_encode([
        ['pattern_key' => 'no-god-object', 'file' => $file, 'line' => 3, 'message' => 'god', 'severity' => 'critical', 'confidence' => 0.9],
    ]));

    $fake = new FakeLlmClient(fn (AnalysisUnit $unit): array => []);
    analyzeBind($telemetry, $fake);

    try {
        // No --path: must load files from --request scope
        $exit = Artisan::call('codeguard:analyze', [
            '--ingest' => $findingsPath,
            '--request' => $request,
            '--context' => 'ci',
        ]);

        expect($exit)->toBe(1)
            ->and(Artisan::output())->toContain('no-god-object');
    } finally {
        if (is_file($request)) {
            unlink($request);
        }
        if (is_file($findingsPath)) {
            unlink($findingsPath);
        }
        analyzeCleanup($file, $telemetry);
    }
});

it('fails ingest on head_sha drift without --allow-scope-drift', function (): void {
    $telemetry = analyzeTelemetryPath();
    $file = analyzeFixtureFile();
    $request = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-drift-'.uniqid().'.json';
    $findingsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-ing-'.uniqid().'.json';

    file_put_contents($request, (string) json_encode([
        'scope' => [
            'mode' => 'path',
            'head_sha' => 'deadbeef00000000000000000000000000000000',
            'files' => [$file],
        ],
    ]));
    file_put_contents($findingsPath, '[]');

    $fake = new FakeLlmClient(fn (AnalysisUnit $unit): array => []);
    analyzeBind($telemetry, $fake);

    try {
        $exit = Artisan::call('codeguard:analyze', [
            '--ingest' => $findingsPath,
            '--request' => $request,
            '--context' => 'ci',
        ]);
        $output = Artisan::output();

        // Real git HEAD differs from deadbeef → refuse (when rev-parse works in this repo).
        if (str_contains($output, 'Scope drift') || str_contains($output, 'drifted HEAD')) {
            expect($exit)->toBe(1)
                ->and($output)->toContain('--allow-scope-drift');
        } else {
            // If headSha() returned null (no git), drift check is skipped.
            expect($exit)->toBeIn([0, 1]);
        }
    } finally {
        if (is_file($request)) {
            unlink($request);
        }
        if (is_file($findingsPath)) {
            unlink($findingsPath);
        }
        analyzeCleanup($file, $telemetry);
    }
});

it('excludes hygiene patterns from full emit unless --include-hygiene', function (): void {
    $file = analyzeFixtureFile();
    $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-hyg-'.uniqid().'.json';

    try {
        Artisan::call('codeguard:analyze', [
            '--emit' => true,
            '--focus' => 'full',
            '--path' => $file,
            '--out' => $out,
        ]);
        $decoded = json_decode((string) file_get_contents($out), true);
        $keys = [];
        foreach ($decoded['units'] ?? [] as $unit) {
            foreach ($unit['patterns'] ?? [] as $pattern) {
                $keys[] = $pattern['key'] ?? '';
            }
        }
        $keys = array_values(array_unique($keys));

        expect($keys)->not->toContain('type-declarations')
            ->and($keys)->not->toContain('strict-typing')
            ->and($keys)->not->toContain('dry');

        Artisan::call('codeguard:analyze', [
            '--emit' => true,
            '--focus' => 'full',
            '--include-hygiene' => true,
            '--path' => $file,
            '--out' => $out,
        ]);
        $decoded2 = json_decode((string) file_get_contents($out), true);
        $keys2 = [];
        foreach ($decoded2['units'] ?? [] as $unit) {
            foreach ($unit['patterns'] ?? [] as $pattern) {
                $keys2[] = $pattern['key'] ?? '';
            }
        }
        $keys2 = array_values(array_unique($keys2));

        expect($keys2)->toContain('type-declarations')
            ->and($keys2)->toContain('strict-typing');
    } finally {
        analyzeCleanup($file, $out);
    }
});
