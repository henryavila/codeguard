<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\StubOverrides;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-overrides-'.uniqid();
    mkdir($this->tempDir, 0o755, true);
    $this->path = $this->tempDir.DIRECTORY_SEPARATOR.'.codeguard'.DIRECTORY_SEPARATOR.'stub-overrides.yaml';
    $this->overrides = new StubOverrides(new Filesystem, $this->path);
});

afterEach(function (): void {
    if (is_dir($this->tempDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        @rmdir($this->tempDir);
    }
});

it('returns empty list when the yaml file does not exist', function (): void {
    expect($this->overrides->load())->toBe([]);
    expect($this->overrides->contains('phpstan.neon'))->toBeFalse();
});

it('creates the directory and writes yaml on first add()', function (): void {
    $this->overrides->add('phpstan.neon');

    expect(file_exists($this->path))->toBeTrue();
    expect($this->overrides->load())->toBe(['phpstan.neon']);
});

it('is idempotent — adding the same path twice keeps a single entry', function (): void {
    $this->overrides->add('phpstan.neon');
    $this->overrides->add('phpstan.neon');

    expect($this->overrides->load())->toBe(['phpstan.neon']);
});

it('persists multiple distinct overrides in sorted order', function (): void {
    $this->overrides->add('phpstan.neon');
    $this->overrides->add('deptrac.yaml');
    $this->overrides->add('pint.json');

    expect($this->overrides->load())->toBe(['deptrac.yaml', 'phpstan.neon', 'pint.json']);
});

it('normalizes leading slashes and backslashes to match StubDefinition paths', function (): void {
    $this->overrides->add('/phpstan.neon');
    $this->overrides->add('tests\\Arch\\TestQualityTest.php');

    expect($this->overrides->contains('phpstan.neon'))->toBeTrue();
    expect($this->overrides->contains('tests/Arch/TestQualityTest.php'))->toBeTrue();
});

it('removes a registered override without touching the others', function (): void {
    $this->overrides->add('phpstan.neon');
    $this->overrides->add('deptrac.yaml');

    $this->overrides->remove('phpstan.neon');

    expect($this->overrides->load())->toBe(['deptrac.yaml']);
    expect($this->overrides->contains('phpstan.neon'))->toBeFalse();
});

it('remove() is a no-op when the path is not registered', function (): void {
    $this->overrides->add('phpstan.neon');
    $before = file_get_contents($this->path);

    $this->overrides->remove('deptrac.yaml');

    expect(file_get_contents($this->path))->toBe($before);
});

it('tolerates corrupt yaml by returning an empty list', function (): void {
    mkdir(dirname($this->path), 0o755, recursive: true);
    file_put_contents($this->path, "::: not valid yaml :::\n  - [");

    expect($this->overrides->load())->toBe([]);
});

it('tolerates yaml without an overrides key', function (): void {
    mkdir(dirname($this->path), 0o755, recursive: true);
    file_put_contents($this->path, "version: 1\nsomething_else: true\n");

    expect($this->overrides->load())->toBe([]);
});

it('ignores non-string entries in the overrides list', function (): void {
    mkdir(dirname($this->path), 0o755, recursive: true);
    file_put_contents(
        $this->path,
        "overrides:\n  - phpstan.neon\n  - 42\n  - null\n  - {nested: x}\n",
    );

    expect($this->overrides->load())->toBe(['phpstan.neon']);
});
