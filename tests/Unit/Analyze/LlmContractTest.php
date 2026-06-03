<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\AnalysisUnit;
use Henryavila\Codeguard\Analyze\Drivers\NullLlmClient;
use Henryavila\Codeguard\Analyze\FindingSchema;

it('null driver adjudicates nothing and reports itself unconfigured', function (): void {
    $client = new NullLlmClient;
    $unit = new AnalysisUnit('/work/Foo.php', []);

    expect($client->isConfigured())->toBeFalse()
        ->and($client->review($unit, 'prompt'))->toBe([]);
});

it('exposes a finding json schema carrying the required keys', function (): void {
    $schema = FindingSchema::jsonSchema();

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['required'])->toContain(
            FindingSchema::KEY_PATTERN,
            FindingSchema::KEY_FILE,
            FindingSchema::KEY_SEVERITY,
            FindingSchema::KEY_CONFIDENCE,
        );
});

it('exposes verified_score as an optional schema property (critique pass, not required)', function (): void {
    $schema = FindingSchema::jsonSchema();

    expect($schema['items']['properties'])->toHaveKey(FindingSchema::KEY_VERIFIED_SCORE)
        ->and($schema['items']['required'])->not->toContain(FindingSchema::KEY_VERIFIED_SCORE);
});

it('exposes related_file as an optional schema property (architectural findings, not required)', function (): void {
    $schema = FindingSchema::jsonSchema();

    expect($schema['items']['properties'])->toHaveKey(FindingSchema::KEY_RELATED_FILE)
        ->and($schema['items']['required'])->not->toContain(FindingSchema::KEY_RELATED_FILE);
});
