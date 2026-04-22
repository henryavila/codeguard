<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\StubOverrides;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->tempApp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-override-cmd-'.uniqid();
    mkdir($this->tempApp, 0o755, true);

    $tempApp = $this->tempApp;
    $this->app->singleton(StubOverrides::class, function () use ($tempApp): StubOverrides {
        return new StubOverrides(
            filesystem: new Filesystem,
            path: $tempApp.'/.codeguard/stub-overrides.yaml',
        );
    });
});

afterEach(function (): void {
    if (! is_dir($this->tempApp)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tempApp, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    @rmdir($this->tempApp);
});

it('adds a known stub path to the overrides file', function (): void {
    $this->artisan('codeguard:install:override', ['path' => 'phpstan.neon'])
        ->assertExitCode(0);

    $overrides = app(StubOverrides::class);
    expect($overrides->contains('phpstan.neon'))->toBeTrue();
});

it('is idempotent — adding twice keeps a single entry', function (): void {
    $this->artisan('codeguard:install:override', ['path' => 'pint.json'])->assertExitCode(0);
    $this->artisan('codeguard:install:override', ['path' => 'pint.json'])->assertExitCode(0);

    $overrides = app(StubOverrides::class);
    expect($overrides->load())->toBe(['pint.json']);
});

it('warns but still adds an unknown stub path', function (): void {
    $this->artisan('codeguard:install:override', ['path' => 'not-a-real-stub.txt'])
        ->expectsOutputToContain('is not a known stub path')
        ->assertExitCode(0);

    $overrides = app(StubOverrides::class);
    expect($overrides->contains('not-a-real-stub.txt'))->toBeTrue();
});

it('removes a path when --remove is passed', function (): void {
    $this->artisan('codeguard:install:override', ['path' => 'phpstan.neon'])->assertExitCode(0);

    $this->artisan('codeguard:install:override', ['path' => 'phpstan.neon', '--remove' => true])
        ->assertExitCode(0);

    $overrides = app(StubOverrides::class);
    expect($overrides->contains('phpstan.neon'))->toBeFalse();
});

it('is a no-op when --remove is passed for a path that was never registered', function (): void {
    $this->artisan('codeguard:install:override', ['path' => 'phpstan.neon', '--remove' => true])
        ->expectsOutputToContain('is not in the overrides list')
        ->assertExitCode(0);
});
