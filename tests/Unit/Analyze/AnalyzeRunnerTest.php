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

function arnRunner(): AnalyzeRunner
{
    $repo = new class implements PatternRepository
    {
        /**
         * @param  list<string>  $presets
         * @return list<Pattern>
         */
        public function forPresets(array $presets): array
        {
            return [Pattern::fromArray('p', [
                'detection' => ['signals' => [['type' => 'file', 'value' => '**/*.php']]],
                'verification' => ['rules' => ['r']],
                'examples' => ['correct' => '', 'violation' => ''],
                'severity' => 'warning',
            ])];
        }

        public function has(string $key): bool
        {
            return $key === 'p';
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

it('builds a work order with one unit per matched file', function (): void {
    $order = arnRunner()->buildWorkOrder(['/work/app/Foo.php'], ['core']);

    expect($order['units'])->toHaveCount(1)
        ->and($order['units'][0]['file'])->toBe('/work/app/Foo.php')
        ->and($order['system_prompt'])->toBeString();
});
