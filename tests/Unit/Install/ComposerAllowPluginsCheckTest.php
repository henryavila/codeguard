<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\AllowPluginsStatus;
use Henryavila\Codeguard\Install\ComposerAllowPluginsCheck;
use Illuminate\Filesystem\Filesystem;

function allowPluginsTempPath(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-allow-plugins-'.uniqid().'.json';
}

function writeComposerJson(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
}

it('returns Allowed when plugin is explicitly true', function (): void {
    $path = allowPluginsTempPath();
    writeComposerJson($path, [
        'name' => 'vendor/project',
        'config' => [
            'allow-plugins' => [
                'captainhook/hook-installer' => true,
            ],
        ],
    ]);

    try {
        $check = new ComposerAllowPluginsCheck(new Filesystem, $path);
        expect($check->check('captainhook/hook-installer'))
            ->toBe(AllowPluginsStatus::Allowed);
    } finally {
        @unlink($path);
    }
});

it('returns NotAllowed when plugin is explicitly false', function (): void {
    $path = allowPluginsTempPath();
    writeComposerJson($path, [
        'config' => [
            'allow-plugins' => [
                'captainhook/hook-installer' => false,
            ],
        ],
    ]);

    try {
        $check = new ComposerAllowPluginsCheck(new Filesystem, $path);
        expect($check->check('captainhook/hook-installer'))
            ->toBe(AllowPluginsStatus::NotAllowed);
    } finally {
        @unlink($path);
    }
});

it('returns NotAllowed when allow-plugins exists but does not list the plugin', function (): void {
    $path = allowPluginsTempPath();
    writeComposerJson($path, [
        'config' => [
            'allow-plugins' => [
                'pestphp/pest-plugin' => true,
            ],
        ],
    ]);

    try {
        $check = new ComposerAllowPluginsCheck(new Filesystem, $path);
        expect($check->check('captainhook/hook-installer'))
            ->toBe(AllowPluginsStatus::NotAllowed);
    } finally {
        @unlink($path);
    }
});

it('returns NotAllowed when there is no config.allow-plugins at all', function (): void {
    $path = allowPluginsTempPath();
    writeComposerJson($path, [
        'name' => 'vendor/project',
    ]);

    try {
        $check = new ComposerAllowPluginsCheck(new Filesystem, $path);
        expect($check->check('captainhook/hook-installer'))
            ->toBe(AllowPluginsStatus::NotAllowed);
    } finally {
        @unlink($path);
    }
});

it('returns Allowed when a wildcard pattern matches the plugin', function (): void {
    $path = allowPluginsTempPath();
    writeComposerJson($path, [
        'config' => [
            'allow-plugins' => [
                'captainhook/*' => true,
            ],
        ],
    ]);

    try {
        $check = new ComposerAllowPluginsCheck(new Filesystem, $path);
        expect($check->check('captainhook/hook-installer'))
            ->toBe(AllowPluginsStatus::Allowed);
    } finally {
        @unlink($path);
    }
});

it('returns Unknown when composer.json does not exist', function (): void {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nonexistent-'.uniqid().'.json';

    $check = new ComposerAllowPluginsCheck(new Filesystem, $path);
    expect($check->check('captainhook/hook-installer'))
        ->toBe(AllowPluginsStatus::Unknown);
});

it('returns Unknown when composer.json is malformed JSON', function (): void {
    $path = allowPluginsTempPath();
    file_put_contents($path, '{ invalid json');

    try {
        $check = new ComposerAllowPluginsCheck(new Filesystem, $path);
        expect($check->check('captainhook/hook-installer'))
            ->toBe(AllowPluginsStatus::Unknown);
    } finally {
        @unlink($path);
    }
});

it('allow() adds the plugin key when config.allow-plugins already exists', function (): void {
    $path = allowPluginsTempPath();
    writeComposerJson($path, [
        'name' => 'vendor/project',
        'config' => [
            'allow-plugins' => [
                'pestphp/pest-plugin' => true,
            ],
        ],
    ]);

    try {
        $check = new ComposerAllowPluginsCheck(new Filesystem, $path);
        $result = $check->allow('captainhook/hook-installer');

        expect($result)->toBeTrue();

        /** @var array<string, mixed> $updated */
        $updated = json_decode((string) file_get_contents($path), true);
        expect($updated['config']['allow-plugins']['captainhook/hook-installer'])->toBeTrue()
            ->and($updated['config']['allow-plugins']['pestphp/pest-plugin'])->toBeTrue()
            ->and($updated['name'])->toBe('vendor/project');
    } finally {
        @unlink($path);
    }
});

it('allow() creates config.allow-plugins when missing', function (): void {
    $path = allowPluginsTempPath();
    writeComposerJson($path, [
        'name' => 'vendor/project',
    ]);

    try {
        $check = new ComposerAllowPluginsCheck(new Filesystem, $path);
        $result = $check->allow('captainhook/hook-installer');

        expect($result)->toBeTrue();

        /** @var array<string, mixed> $updated */
        $updated = json_decode((string) file_get_contents($path), true);
        expect($updated['config']['allow-plugins']['captainhook/hook-installer'])->toBeTrue();
    } finally {
        @unlink($path);
    }
});

it('allow() flips an explicit false to true', function (): void {
    $path = allowPluginsTempPath();
    writeComposerJson($path, [
        'config' => [
            'allow-plugins' => [
                'captainhook/hook-installer' => false,
            ],
        ],
    ]);

    try {
        $check = new ComposerAllowPluginsCheck(new Filesystem, $path);
        $result = $check->allow('captainhook/hook-installer');

        expect($result)->toBeTrue();

        $check2 = new ComposerAllowPluginsCheck(new Filesystem, $path);
        expect($check2->check('captainhook/hook-installer'))
            ->toBe(AllowPluginsStatus::Allowed);
    } finally {
        @unlink($path);
    }
});

it('allow() returns false when composer.json does not exist', function (): void {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nonexistent-'.uniqid().'.json';

    $check = new ComposerAllowPluginsCheck(new Filesystem, $path);
    expect($check->allow('captainhook/hook-installer'))->toBeFalse();
});

it('allow() refuses to overwrite allow-plugins:false (deny-all shorthand)', function (): void {
    $path = allowPluginsTempPath();
    file_put_contents($path, json_encode([
        'name' => 'vendor/project',
        'config' => [
            'allow-plugins' => false,
        ],
    ], JSON_PRETTY_PRINT));

    $before = file_get_contents($path);

    try {
        $check = new ComposerAllowPluginsCheck(new Filesystem, $path);
        $result = $check->allow('captainhook/hook-installer');

        expect($result)->toBeFalse();
        expect(file_get_contents($path))->toBe($before);
    } finally {
        @unlink($path);
    }
});

it('allow() preserves file permissions when rewriting composer.json', function (): void {
    $path = allowPluginsTempPath();
    writeComposerJson($path, ['name' => 'vendor/project']);
    chmod($path, 0o640);

    try {
        $check = new ComposerAllowPluginsCheck(new Filesystem, $path);
        expect($check->allow('captainhook/hook-installer'))->toBeTrue();

        $perms = fileperms($path) & 0o777;
        expect($perms)->toBe(0o640);
    } finally {
        @unlink($path);
    }
});
