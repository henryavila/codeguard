<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\EnvironmentDetector;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-env-pest-'.uniqid();
    mkdir($this->tempDir, 0o755, true);
});

afterEach(function (): void {
    if (! is_dir($this->tempDir)) {
        return;
    }
    foreach (glob($this->tempDir.'/*') as $file) {
        @unlink($file);
    }
    @rmdir($this->tempDir);
});

function makeDetector(string $basePath): EnvironmentDetector
{
    return new EnvironmentDetector(new Filesystem, $basePath);
}

it('detects pestphp/pest under require-dev in composer.json', function (): void {
    file_put_contents($this->tempDir.'/composer.json', json_encode([
        'require-dev' => ['pestphp/pest' => '^3.0'],
    ]));

    expect(makeDetector($this->tempDir)->detectPestUsage())->toBeTrue();
});

it('detects pestphp/pest under require (rare but valid)', function (): void {
    file_put_contents($this->tempDir.'/composer.json', json_encode([
        'require' => ['pestphp/pest' => '^3.0'],
    ]));

    expect(makeDetector($this->tempDir)->detectPestUsage())->toBeTrue();
});

it('returns false when composer.json is absent', function (): void {
    expect(makeDetector($this->tempDir)->detectPestUsage())->toBeFalse();
});

it('returns false when composer.json exists without pestphp/pest', function (): void {
    file_put_contents($this->tempDir.'/composer.json', json_encode([
        'require' => ['laravel/framework' => '^11.0'],
        'require-dev' => ['phpunit/phpunit' => '^10.0'],
    ]));

    expect(makeDetector($this->tempDir)->detectPestUsage())->toBeFalse();
});

it('returns false when composer.json is malformed JSON', function (): void {
    file_put_contents($this->tempDir.'/composer.json', '{ not valid json');

    expect(makeDetector($this->tempDir)->detectPestUsage())->toBeFalse();
});

it('exposes usesPest via detect()', function (): void {
    file_put_contents($this->tempDir.'/composer.json', json_encode([
        'require-dev' => ['pestphp/pest' => '^3.0'],
    ]));

    expect(makeDetector($this->tempDir)->detect()->usesPest)->toBeTrue();
});
