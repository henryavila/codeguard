<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\LayerDecision;
use Henryavila\Codeguard\Install\LayerDecisionStore;
use Henryavila\Codeguard\Install\WizardResult;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-store-'.uniqid();
    mkdir($this->tempDir, 0o755, true);
    $this->path = $this->tempDir.DIRECTORY_SEPARATOR.'layer-decisions.yaml';
    $this->store = new LayerDecisionStore(new Filesystem(), $this->path);
});

afterEach(function (): void {
    if (is_dir($this->tempDir)) {
        foreach (glob($this->tempDir.'/*') as $file) {
            @unlink($file);
        }

        @rmdir($this->tempDir);
    }
});

it('load() returns an empty array when file does not exist', function (): void {
    expect($this->store->load())->toBe([]);
});

it('save() then load() round-trips decisions including skips', function (): void {
    $result = new WizardResult(
        decisions: [
            LayerDecision::assign('App\\Upgrades', 'Domain'),
            LayerDecision::skip('App\\Features'),
            LayerDecision::assign('App\\Integrations', 'Integration'),
        ],
        customLayers: ['Integration'],
    );

    $this->store->save($result);

    $loaded = $this->store->load();

    expect($loaded)->toBe([
        'App\\Upgrades' => 'Domain',
        'App\\Features' => '',
        'App\\Integrations' => 'Integration',
    ]);
});

it('save() does not create a file when result is empty and no file exists', function (): void {
    $emptyResult = new WizardResult(decisions: [], customLayers: []);

    $this->store->save($emptyResult);

    expect(file_exists($this->path))->toBeFalse();
});

it('load() returns an empty array when YAML is malformed', function (): void {
    file_put_contents($this->path, "decisions:\n  this is: not: valid yaml: ]]]\n");

    expect($this->store->load())->toBe([]);
});

it('save() writes an explanatory comment at the top of the file', function (): void {
    $result = new WizardResult(
        decisions: [LayerDecision::assign('App\\Upgrades', 'Domain')],
        customLayers: [],
    );

    $this->store->save($result);

    $contents = file_get_contents($this->path);

    expect($contents)->toContain('# CodeGuard')
        ->and($contents)->toContain('codeguard:install');
});
