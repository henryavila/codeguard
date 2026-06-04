<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\AnalyzeBaseline;
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

/*
|--------------------------------------------------------------------------
| R3 — architectural (graph-level) patterns
|--------------------------------------------------------------------------
|
| The 3 catch-all-import patterns (layer-dependency-direction, bounded-contexts,
| no-circular-dependencies) are NOT selected per file. This suite proves the
| work order carries the namespace graph + those patterns, and that an
| architectural finding attributes on ingest even to a class file that matched
| no per-file pattern.
|
*/

function archBase(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-arch-'.uniqid();
}

function archWrite(string $base, string $relative, string $contents): string
{
    $path = $base.DIRECTORY_SEPARATOR.$relative;
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }
    file_put_contents($path, $contents);

    return $path;
}

function archCleanup(string $base): void
{
    if (! is_dir($base)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($base);
}

function archRunner(string $base): AnalyzeRunner
{
    $repo = new class implements PatternRepository
    {
        /**
         * @param  list<string>  $presets
         * @return list<Pattern>
         */
        public function forPresets(array $presets): array
        {
            return [Pattern::fromArray('layer-dependency-direction', [
                'detection' => ['signals' => [['type' => 'import', 'value' => '**/*']]],
                'verification' => ['rules' => ['upper layers depend on lower, never the reverse']],
                'examples' => ['correct' => '', 'violation' => ''],
                'severity' => 'critical',
            ])];
        }

        public function has(string $key): bool
        {
            return $key === 'layer-dependency-direction';
        }
    };

    $recorder = new Recorder(
        new ConfigGate(enabled: false),
        new FieldAllowlist(strictMode: true),
        new Rotator,
        new JsonlWriter,
        sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-arch.jsonl',
    );

    $baseline = new AnalyzeBaseline(
        new Filesystem,
        sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-arch-baseline-'.uniqid().'.json',
        $base,
    );

    return new AnalyzeRunner($recorder, $repo, new PatternMatcher($base), new NullLlmClient, $baseline, '/nonexistent-prompt.md');
}

it('emits the namespace graph and the architectural patterns in the work order', function (): void {
    $base = archBase();

    try {
        $a = archWrite($base, 'app/Services/OrderService.php', "<?php\nnamespace App\\Services;\nuse App\\Repositories\\OrderRepository;\nclass OrderService {}\n");
        $b = archWrite($base, 'app/Repositories/OrderRepository.php', "<?php\nnamespace App\\Repositories;\nclass OrderRepository {}\n");

        $order = archRunner($base)->buildWorkOrder([$a, $b], ['core']);

        expect($order)->toHaveKeys(['graph', 'architecture'])
            ->and($order['architecture']['patterns'])->toHaveCount(1)
            ->and($order['architecture']['patterns'][0]['key'])->toBe('layer-dependency-direction')
            ->and($order['graph']['nodes'])->toHaveCount(2)
            ->and($order['graph']['edges'])->toContain([
                'from' => 'App\\Services\\OrderService',
                'to' => 'App\\Repositories\\OrderRepository',
            ])
            // Architectural patterns are NOT duplicated into the per-file units.
            ->and($order['units'])->toBe([]);
    } finally {
        archCleanup($base);
    }
});

it('attributes an architectural finding to a class file that matched no per-file pattern', function (): void {
    $base = archBase();

    try {
        $repo = archWrite($base, 'app/Repositories/OrderRepository.php', "<?php\nnamespace App\\Repositories;\nuse App\\Http\\OrderController;\nclass OrderRepository {}\n");

        $finding = [
            'pattern_key' => 'layer-dependency-direction',
            'file' => $repo,
            'line' => 3,
            'message' => 'repository depends on a controller (wrong direction)',
            'severity' => 'critical',
            'confidence' => 0.9,
            'related_file' => 'App\\Http\\OrderController',
        ];

        $result = archRunner($base)->ingest([$repo], ['core'], [$finding], Severity::Critical);

        expect($result->matchesCount())->toBe(1)
            ->and($result->matches[0]->patternKey)->toBe('layer-dependency-direction')
            ->and($result->matches[0]->relatedFile)->toBe('App\\Http\\OrderController');
    } finally {
        archCleanup($base);
    }
});

it('drops an architectural finding pointing at a non-class file in scope', function (): void {
    $base = archBase();

    try {
        $config = archWrite($base, 'config/app.php', "<?php\nreturn ['x' => 1];\n");

        $finding = [
            'pattern_key' => 'layer-dependency-direction',
            'file' => $config,
            'line' => 1,
            'message' => 'should not attribute — not a class file',
            'severity' => 'critical',
            'confidence' => 0.9,
        ];

        $result = archRunner($base)->ingest([$config], ['core'], [$finding], Severity::Critical);

        expect($result->matchesCount())->toBe(0);
    } finally {
        archCleanup($base);
    }
});
