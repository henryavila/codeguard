<?php

declare(strict_types=1);

use Henryavila\Codeguard\Telemetry\ConfigGate;
use Henryavila\Codeguard\Telemetry\TelemetryStateStore;

beforeEach(function (): void {
    $this->tempApp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-telemetry-cmd-'.uniqid();
    mkdir($this->tempApp.DIRECTORY_SEPARATOR.'.codeguard', 0o755, recursive: true);

    $this->app->singleton(TelemetryStateStore::class, fn (): TelemetryStateStore => new TelemetryStateStore(
        stateFilePath: $this->tempApp.DIRECTORY_SEPARATOR.'.codeguard'.DIRECTORY_SEPARATOR.'telemetry-state.json',
    ));
});

afterEach(function (): void {
    if (isset($this->tempApp) && is_dir($this->tempApp)) {
        $dir = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempApp, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($dir as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->tempApp);
    }
});

it('enable command persists enabled=true to the state file', function (): void {
    $this->artisan('codeguard:telemetry:enable')->assertSuccessful();

    /** @var TelemetryStateStore $store */
    $store = $this->app->make(TelemetryStateStore::class);
    expect($store->read())->toBeTrue()
        ->and(file_exists($store->path()))->toBeTrue();
});

it('disable command persists enabled=false to the state file', function (): void {
    $this->artisan('codeguard:telemetry:disable')->assertSuccessful();

    /** @var TelemetryStateStore $store */
    $store = $this->app->make(TelemetryStateStore::class);
    expect($store->read())->toBeFalse();
});

it('state file takes precedence over config value for ConfigGate', function (): void {
    // Force config to report true; state file says false.
    config()->set('codeguard.telemetry.enabled', true);

    /** @var TelemetryStateStore $store */
    $store = $this->app->make(TelemetryStateStore::class);
    $store->write(false);

    // Rebind ConfigGate so the factory runs with the new state.
    $this->app->forgetInstance(ConfigGate::class);
    /** @var ConfigGate $gate */
    $gate = $this->app->make(ConfigGate::class);

    expect($gate->isEnabled())->toBeFalse();
});

it('config fallback kicks in when the state file is absent', function (): void {
    config()->set('codeguard.telemetry.enabled', true);
    $this->app->forgetInstance(ConfigGate::class);

    /** @var ConfigGate $gate */
    $gate = $this->app->make(ConfigGate::class);
    expect($gate->isEnabled())->toBeTrue();
});

it('clear command reports zero work when no telemetry files exist', function (): void {
    $this->artisan('codeguard:telemetry:clear', ['--force' => true])
        ->expectsOutputToContain('No telemetry files to clear')
        ->assertSuccessful();
});

it('clear command exits 0 when the user declines the confirmation', function (): void {
    $active = $this->tempApp.DIRECTORY_SEPARATOR.'.codeguard'.DIRECTORY_SEPARATOR.'telemetry.jsonl';
    file_put_contents($active, "{\"ok\":1}\n");

    $this->artisan('codeguard:telemetry:clear')
        ->expectsConfirmation('Delete 1 telemetry file(s)?', 'no')
        ->expectsOutputToContain('Aborted — nothing deleted')
        ->assertSuccessful();

    // File is preserved when the user says no.
    expect(file_exists($active))->toBeTrue();
});

it('clear command deletes telemetry files when forced', function (): void {
    $active = $this->tempApp.DIRECTORY_SEPARATOR.'.codeguard'.DIRECTORY_SEPARATOR.'telemetry.jsonl';
    $archive = $this->tempApp.DIRECTORY_SEPARATOR.'.codeguard'.DIRECTORY_SEPARATOR.'telemetry-2026-04-20-120000.jsonl';
    file_put_contents($active, "{\"ok\":1}\n");
    file_put_contents($archive, "{\"ok\":2}\n");

    $this->artisan('codeguard:telemetry:clear', ['--force' => true])
        ->expectsOutputToContain('Cleared 2 telemetry file(s)')
        ->assertSuccessful();

    expect(file_exists($active))->toBeFalse()
        ->and(file_exists($archive))->toBeFalse();
});
