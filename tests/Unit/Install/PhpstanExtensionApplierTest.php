<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\PhpstanExtension;
use Henryavila\Codeguard\Install\PhpstanExtensionApplier;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-applier-'.uniqid();
    mkdir($this->tempDir, 0o755, true);
    $this->path = $this->tempDir.DIRECTORY_SEPARATOR.'phpstan.neon';
    $this->applier = new PhpstanExtensionApplier(new Filesystem());
});

afterEach(function (): void {
    if (is_dir($this->tempDir)) {
        foreach (glob($this->tempDir.'/*') as $file) {
            @unlink($file);
        }

        @rmdir($this->tempDir);
    }
});

it('does nothing when the target file does not exist', function (): void {
    $this->applier->apply('/nonexistent.neon', PhpstanExtension::cases());

    expect(true)->toBeTrue();
});

it('comments out inline-sentinel lines for disabled extensions', function (): void {
    file_put_contents($this->path, <<<'NEON'
includes:
    - vendor/larastan/larastan/extension.neon  # @codeguard:ext=larastan
    - vendor/shipmonk/dead-code-detector/rules.neon  # @codeguard:ext=dead-code
NEON);

    $this->applier->apply($this->path, [PhpstanExtension::Larastan]);

    $contents = file_get_contents($this->path);

    expect($contents)->toContain('- vendor/larastan/larastan/extension.neon  # @codeguard:ext=larastan')
        ->and($contents)->toMatch('/#\s+-\s+vendor\/shipmonk/');
});

it('re-enables previously commented inline-sentinel lines when re-selected', function (): void {
    file_put_contents($this->path, <<<'NEON'
includes:
    # - vendor/larastan/larastan/extension.neon  # @codeguard:ext=larastan
NEON);

    $this->applier->apply($this->path, [PhpstanExtension::Larastan]);

    $contents = file_get_contents($this->path);

    expect($contents)->toContain('- vendor/larastan/larastan/extension.neon  # @codeguard:ext=larastan')
        ->and($contents)->not->toMatch('/#\s+-\s+vendor\/larastan/');
});

it('comments out multi-line parameter block between start/end sentinels', function (): void {
    file_put_contents($this->path, <<<'NEON'
parameters:
    # @codeguard:ext=cognitive-complexity:params:start
    cognitive_complexity:
        class: 50
        function: 15
    # @codeguard:ext=cognitive-complexity:params:end
NEON);

    $this->applier->apply($this->path, []);

    $contents = file_get_contents($this->path);

    expect($contents)->toMatch('/#\s+cognitive_complexity:/')
        ->and($contents)->toMatch('/#\s+class: 50/')
        ->and($contents)->toMatch('/#\s+function: 15/');
});

it('re-enables commented parameter block when extension selected', function (): void {
    file_put_contents($this->path, <<<'NEON'
parameters:
    # @codeguard:ext=cognitive-complexity:params:start
    # cognitive_complexity:
    #     class: 50
    #     function: 15
    # @codeguard:ext=cognitive-complexity:params:end
NEON);

    $this->applier->apply($this->path, [PhpstanExtension::CognitiveComplexity]);

    $contents = file_get_contents($this->path);

    expect($contents)->toContain('    cognitive_complexity:')
        ->and($contents)->toContain('        class: 50');
});
