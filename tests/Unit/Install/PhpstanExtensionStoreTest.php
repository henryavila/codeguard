<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\PhpstanExtension;
use Henryavila\Codeguard\Install\PhpstanExtensionStore;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-phpstan-store-'.uniqid();
    mkdir($this->tempDir, 0o755, true);
    $this->path = $this->tempDir.DIRECTORY_SEPARATOR.'phpstan-extensions.yaml';
    $this->store = new PhpstanExtensionStore(new Filesystem, $this->path);
});

afterEach(function (): void {
    if (is_dir($this->tempDir)) {
        foreach (glob($this->tempDir.'/*') as $file) {
            @unlink($file);
        }

        @rmdir($this->tempDir);
    }
});

it('load() returns empty array when file does not exist', function (): void {
    expect($this->store->load())->toBe([]);
});

it('save() then load() round-trips enum cases', function (): void {
    $this->store->save([
        PhpstanExtension::Larastan,
        PhpstanExtension::DeadCode,
        PhpstanExtension::TestQuality,
    ]);

    $loaded = $this->store->load();

    expect($loaded)->toBe([
        PhpstanExtension::Larastan,
        PhpstanExtension::DeadCode,
        PhpstanExtension::TestQuality,
    ]);
});

it('load() ignores unknown enum values without failing', function (): void {
    file_put_contents($this->path, "enabled:\n  - larastan\n  - unknown-extension\n  - dead-code\n");

    $loaded = $this->store->load();

    expect($loaded)->toHaveCount(2)
        ->and($loaded[0])->toBe(PhpstanExtension::Larastan)
        ->and($loaded[1])->toBe(PhpstanExtension::DeadCode);
});

it('save() writes an explanatory comment at the top of the file', function (): void {
    $this->store->save([PhpstanExtension::Larastan]);

    $contents = file_get_contents($this->path);

    expect($contents)->toContain('# CodeGuard')
        ->and($contents)->toContain('codeguard:install');
});

it('load() tolerates malformed YAML without throwing', function (): void {
    file_put_contents($this->path, "enabled: [ broken: yaml\n");

    expect($this->store->load())->toBe([]);
});
