<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\LegacyStub;
use Henryavila\Codeguard\Install\LegacyStubCleaner;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->tempApp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-legacy-'.uniqid();
    mkdir($this->tempApp, 0o755, true);
    $this->cleaner = new LegacyStubCleaner(new Filesystem, $this->tempApp);
});

afterEach(function (): void {
    if (is_dir($this->tempApp)) {
        foreach (glob($this->tempApp.'/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->tempApp);
    }
});

it('returns an empty list when no legacy stubs are present', function (): void {
    expect($this->cleaner->detect())->toBe([]);
});

it('detects lefthook.yml when it exists in the project root', function (): void {
    file_put_contents($this->tempApp.'/lefthook.yml', "pre-commit: {}\n");

    $detected = $this->cleaner->detect();

    expect($detected)->toHaveCount(1);
    expect($detected[0])->toBeInstanceOf(LegacyStub::class);
    expect($detected[0]->path)->toBe('lefthook.yml');
    expect($detected[0]->replacement)->toBe('captainhook.json');
});

it('deletes a detected legacy stub', function (): void {
    $path = $this->tempApp.'/lefthook.yml';
    file_put_contents($path, "x\n");

    [$stub] = $this->cleaner->detect();
    $deleted = $this->cleaner->delete($stub);

    expect($deleted)->toBeTrue();
    expect(file_exists($path))->toBeFalse();
});

it('returns false from delete() when the file no longer exists', function (): void {
    $stub = new LegacyStub(path: 'lefthook.yml', replacement: 'captainhook.json', reason: 'x');

    expect($this->cleaner->delete($stub))->toBeFalse();
});

it('exposes absolutePath() against the configured basePath', function (): void {
    expect($this->cleaner->absolutePath('lefthook.yml'))
        ->toBe($this->tempApp.DIRECTORY_SEPARATOR.'lefthook.yml');
});

it('legacyStubs() lists every path the installer should offer to clean up', function (): void {
    $paths = array_map(
        static fn (LegacyStub $stub): string => $stub->path,
        $this->cleaner->legacyStubs(),
    );

    expect($paths)->toContain('lefthook.yml');
});
