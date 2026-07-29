<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\AnalyzeBaseline;
use Henryavila\Codeguard\Analyze\AnalyzeOptions;
use Henryavila\Codeguard\Analyze\AnalyzeRunner;
use Henryavila\Codeguard\Analyze\Drivers\NullLlmClient;
use Henryavila\Codeguard\Analyze\Pattern;
use Henryavila\Codeguard\Analyze\PatternMatcher;
use Henryavila\Codeguard\Analyze\PatternRepository;
use Henryavila\Codeguard\Analyze\Severity;
use Henryavila\Codeguard\Telemetry\ConfigGate;
use Henryavila\Codeguard\Telemetry\FieldAllowlist;
use Henryavila\Codeguard\Telemetry\JsonlWriter;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Telemetry\Rotator;
use Illuminate\Filesystem\Filesystem;

/**
 * @param  list<string>  $keys
 */
function arnRunner(array $keys = ['p']): AnalyzeRunner
{
    $repo = new class($keys) implements PatternRepository
    {
        /** @param  list<string>  $keys */
        public function __construct(private readonly array $keys) {}

        /**
         * @param  list<string>  $presets
         * @return list<Pattern>
         */
        public function forPresets(array $presets): array
        {
            return array_map(
                static fn (string $key): Pattern => Pattern::fromArray($key, [
                    'detection' => ['signals' => [['type' => 'file', 'value' => '**/*.php']]],
                    'verification' => ['rules' => ['r']],
                    'examples' => ['correct' => '', 'violation' => ''],
                    'severity' => 'warning',
                ]),
                $this->keys,
            );
        }

        public function has(string $key): bool
        {
            return in_array($key, $this->keys, true);
        }
    };

    // ConfigGate(false) => telemetry disabled => Recorder does zero I/O.
    $recorder = new Recorder(
        new ConfigGate(enabled: false),
        new FieldAllowlist(strictMode: true),
        new Rotator,
        new JsonlWriter,
        sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-arn.jsonl',
    );

    $baseline = new AnalyzeBaseline(
        new Filesystem,
        sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-arn-baseline-'.uniqid().'.json',
        '/work',
    );

    return new AnalyzeRunner($recorder, $repo, new PatternMatcher('/work'), new NullLlmClient, $baseline, '/nonexistent-prompt.md');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function arnFinding(array $overrides = []): array
{
    return array_merge([
        'pattern_key' => 'p',
        'file' => '/work/app/DTOs/User.php',
        'line' => 1,
        'message' => 'm',
        'severity' => 'warning',
        'confidence' => 0.9,
    ], $overrides);
}

it('attributes an ingested finding to the exact-path unit, not a basename twin', function (): void {
    $files = ['/work/app/Models/User.php', '/work/app/DTOs/User.php'];

    $result = arnRunner()->ingest($files, ['core'], [arnFinding()], Severity::Warning);

    expect($result->matchesCount())->toBe(1)
        ->and($result->matches[0]->file)->toBe('/work/app/DTOs/User.php');
});

it('drops a finding whose file is an ambiguous basename with no exact match', function (): void {
    $files = ['/work/app/Models/User.php', '/work/app/DTOs/User.php'];

    $result = arnRunner()->ingest($files, ['core'], [arnFinding(['file' => 'User.php'])], Severity::Warning);

    expect($result->matchesCount())->toBe(0);
});

it('attributes a finding citing a working-dir-relative path to its absolute-path unit despite a basename twin', function (): void {
    // The namespace graph emits working-dir-relative paths (e.g. app/Models/User.php);
    // a subagent echoes one back for an architectural finding. With two User.php units
    // the basename fallback is ambiguous, so attribution must resolve the relative path.
    $files = ['/work/app/Models/User.php', '/work/app/DTOs/User.php'];

    $result = arnRunner()->ingest($files, ['core'], [arnFinding(['file' => 'app/Models/User.php'])], Severity::Warning);

    expect($result->matchesCount())->toBe(1)
        ->and($result->matches[0]->file)->toBe('/work/app/Models/User.php');
});

it('builds a work order with one unit per matched file', function (): void {
    $order = arnRunner()->buildWorkOrder(['/work/app/Foo.php'], ['core']);

    expect($order['units'])->toHaveCount(1)
        ->and($order['units'][0]['file'])->toBe('/work/app/Foo.php')
        ->and($order['system_prompt'])->toBeString()
        ->and($order['samples'])->toBe(1);
});

it('carries the requested sample count into the work order', function (): void {
    $order = arnRunner()->buildWorkOrder(['/work/app/Foo.php'], ['core'], 3);

    expect($order['samples'])->toBe(3);
});

it('votes across samples on ingest — keeps a majority finding with vote-share confidence', function (): void {
    $files = ['/work/app/DTOs/User.php'];
    $samples = [[arnFinding()], [arnFinding()], []];

    $result = arnRunner()->ingestSamples($files, ['core'], $samples, Severity::Warning, minVotes: 2);

    expect($result->matchesCount())->toBe(1)
        ->and($result->matches[0]->confidence)->toBe(2 / 3);
});

it('drops a finding below the vote threshold on ingestSamples', function (): void {
    $files = ['/work/app/DTOs/User.php'];
    $samples = [[arnFinding()], [], []];

    $result = arnRunner()->ingestSamples($files, ['core'], $samples, Severity::Warning, minVotes: 2);

    expect($result->matchesCount())->toBe(0);
});

it('drops a finding the critique pass scored 0, keeping a positively-scored one', function (): void {
    $files = ['/work/app/DTOs/User.php'];
    $findings = [
        arnFinding(['line' => 1, 'verified_score' => 0]),  // critique rejected
        arnFinding(['line' => 2, 'verified_score' => 8]),  // critique kept
    ];

    $result = arnRunner()->ingest($files, ['core'], $findings, Severity::Warning);

    expect($result->matchesCount())->toBe(1)
        ->and($result->matches[0]->line)->toBe(2)
        ->and($result->matches[0]->verifiedScore)->toBe(8);
});

it('drops soft critique scores below the configured floor while keeping uncritiqued findings', function (): void {
    $files = ['/work/app/DTOs/User.php'];
    $findings = [
        arnFinding(['line' => 1, 'verified_score' => 3]), // below contractor floor
        arnFinding(['line' => 2, 'verified_score' => 4]), // on the floor
        arnFinding(['line' => 3]), // uncritiqued — always kept
    ];

    $result = arnRunner()->ingest(
        $files,
        ['core'],
        $findings,
        Severity::Warning,
        AnalyzeOptions::full(minCritiqueScore: 4),
    );

    expect($result->matchesCount())->toBe(2)
        ->and(array_map(static fn ($m) => $m->line, $result->matches))->toBe([2, 3]);
});

it('applies the critique drop after voting on ingestSamples', function (): void {
    $files = ['/work/app/DTOs/User.php'];
    $rejected = arnFinding(['verified_score' => 0]);
    $samples = [[$rejected], [$rejected], [$rejected]]; // unanimous, but critique killed it

    $result = arnRunner()->ingestSamples($files, ['core'], $samples, Severity::Warning, minVotes: 2);

    expect($result->matchesCount())->toBe(0);
});

it('runs ingestSamples findings through the same trust boundary (drops hallucinations before voting)', function (): void {
    $files = ['/work/app/DTOs/User.php'];
    $hallucinated = arnFinding(['pattern_key' => 'ghost-pattern']);
    $samples = [
        [arnFinding(), $hallucinated],
        [arnFinding(), $hallucinated],
        [$hallucinated],
    ];

    $result = arnRunner()->ingestSamples($files, ['core'], $samples, Severity::Warning, minVotes: 2);

    // The real finding has 2 votes and survives; the ghost is dropped before voting.
    expect($result->matchesCount())->toBe(1)
        ->and($result->matches[0]->patternKey)->toBe('p');
});

it('includes scope object with files on buildWorkOrder', function (): void {
    $files = ['/work/app/Services/Foo.php'];
    $order = arnRunner()->buildWorkOrder($files, ['core'], scope: [
        'mode' => 'base',
        'base_ref' => 'origin/main',
        'committed_only' => false,
        'path' => null,
        'head_sha' => 'abc',
        'merge_base_sha' => 'def',
        'files' => $files,
    ]);

    expect($order['scope']['mode'] ?? null)->toBe('base')
        ->and($order['scope']['base_ref'] ?? null)->toBe('origin/main')
        ->and($order['scope']['head_sha'] ?? null)->toBe('abc')
        ->and($order['scope']['files'] ?? null)->toBe($files);
});

it('excludes hygiene classifications from full focus by default', function (): void {
    $repo = new class implements PatternRepository
    {
        public function forPresets(array $presets): array
        {
            return [
                Pattern::fromArray('type-declarations', [
                    'classification' => 'hygiene',
                    'detection' => ['signals' => [['type' => 'file', 'value' => '**/*.php']]],
                    'verification' => ['rules' => ['r']],
                    'examples' => ['correct' => '', 'violation' => ''],
                    'severity' => 'suggestion',
                ]),
                Pattern::fromArray('raw-sql-injection', [
                    'classification' => 'mvp',
                    'detection' => ['signals' => [['type' => 'file', 'value' => '**/*.php']]],
                    'verification' => ['rules' => ['r']],
                    'examples' => ['correct' => '', 'violation' => ''],
                    'severity' => 'critical',
                ]),
            ];
        }

        public function has(string $key): bool
        {
            return true;
        }
    };

    $recorder = new Recorder(
        new ConfigGate(enabled: false),
        new FieldAllowlist(strictMode: true),
        new Rotator,
        new JsonlWriter,
        sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-arn-hygiene.jsonl',
    );
    $baseline = new AnalyzeBaseline(
        new Filesystem,
        sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-arn-baseline-'.uniqid().'.json',
        '/work',
    );
    $runner = new AnalyzeRunner(
        $recorder,
        $repo,
        new PatternMatcher('/work'),
        new NullLlmClient,
        $baseline,
        '/nonexistent-prompt.md',
    );

    $order = $runner->buildWorkOrder(['/work/app/Foo.php'], ['core'], options: AnalyzeOptions::full());
    $keys = array_map(
        static fn (array $p): string => $p['key'],
        $order['units'][0]['patterns'] ?? [],
    );

    expect($keys)->toContain('raw-sql-injection')
        ->and($keys)->not->toContain('type-declarations');

    $withHygiene = $runner->buildWorkOrder(
        ['/work/app/Foo.php'],
        ['core'],
        options: AnalyzeOptions::full(includeHygiene: true),
    );
    $keysH = array_map(
        static fn (array $p): string => $p['key'],
        $withHygiene['units'][0]['patterns'] ?? [],
    );
    expect($keysH)->toContain('type-declarations');
});

it('filters emit + ingest to contractor pattern keys only', function (): void {
    $files = ['/work/app/DTOs/User.php'];
    $runner = arnRunner(['raw-sql-injection', 'type-declarations', 'service-layer']);
    $opts = new AnalyzeOptions(
        onlyPatternKeys: ['raw-sql-injection', 'service-layer'],
        minCritiqueScore: 4,
    );

    $order = $runner->buildWorkOrder($files, ['core'], samples: 1, critique: true, options: $opts);
    $keys = array_map(
        static fn (array $p): string => $p['key'],
        $order['units'][0]['patterns'] ?? [],
    );

    expect($keys)->toContain('raw-sql-injection')
        ->and($keys)->toContain('service-layer')
        ->and($keys)->not->toContain('type-declarations')
        ->and($order['min_critique_score'] ?? null)->toBe(4);

    $findings = [
        arnFinding(['pattern_key' => 'raw-sql-injection', 'line' => 1, 'verified_score' => 8]),
        arnFinding(['pattern_key' => 'type-declarations', 'line' => 2, 'verified_score' => 9]),
        arnFinding(['pattern_key' => 'service-layer', 'line' => 3, 'verified_score' => 2]), // soft — floor 4
    ];
    $result = $runner->ingest($files, ['core'], $findings, Severity::Warning, $opts);

    expect($result->matchesCount())->toBe(1)
        ->and($result->matches[0]->patternKey)->toBe('raw-sql-injection');
});
