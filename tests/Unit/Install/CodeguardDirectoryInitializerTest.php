<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\CodeguardDirectoryInitializer;
use Illuminate\Filesystem\Filesystem;

function codeguardInitTempDir(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-init-'.uniqid();
    mkdir($dir, 0o755, true);

    return $dir;
}

function codeguardInitCleanup(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $entries = scandir($dir) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir.DIRECTORY_SEPARATOR.$entry;
        is_dir($path) ? codeguardInitCleanup($path) : @unlink($path);
    }
    @rmdir($dir);
}

it('creates .codeguard/ and writes .gitignore with all canonical entries on first run', function (): void {
    $base = codeguardInitTempDir();

    try {
        $initializer = new CodeguardDirectoryInitializer(new Filesystem, $base);
        $initializer->initialize();

        $dir = $base.DIRECTORY_SEPARATOR.'.codeguard';
        $gitignore = $dir.DIRECTORY_SEPARATOR.'.gitignore';

        expect(is_dir($dir))->toBeTrue()
            ->and(file_exists($gitignore))->toBeTrue();

        $content = file_get_contents($gitignore);

        foreach (CodeguardDirectoryInitializer::requiredEntries() as $entry) {
            expect($content)->toContain($entry);
        }
    } finally {
        codeguardInitCleanup($base);
    }
});

it('does not overwrite an existing .codeguard/ directory', function (): void {
    $base = codeguardInitTempDir();
    $dir = $base.DIRECTORY_SEPARATOR.'.codeguard';
    mkdir($dir, 0o755);
    file_put_contents($dir.DIRECTORY_SEPARATOR.'some-existing-file.txt', 'user data');

    try {
        $initializer = new CodeguardDirectoryInitializer(new Filesystem, $base);
        $initializer->initialize();

        expect(file_exists($dir.DIRECTORY_SEPARATOR.'some-existing-file.txt'))->toBeTrue()
            ->and(file_get_contents($dir.DIRECTORY_SEPARATOR.'some-existing-file.txt'))->toBe('user data');
    } finally {
        codeguardInitCleanup($base);
    }
});

it('appends missing canonical entries without removing user-added entries', function (): void {
    $base = codeguardInitTempDir();
    $dir = $base.DIRECTORY_SEPARATOR.'.codeguard';
    mkdir($dir, 0o755);
    file_put_contents(
        $dir.DIRECTORY_SEPARATOR.'.gitignore',
        "# custom section\nmy-local-notes.md\n",
    );

    try {
        $initializer = new CodeguardDirectoryInitializer(new Filesystem, $base);
        $initializer->initialize();

        $content = file_get_contents($dir.DIRECTORY_SEPARATOR.'.gitignore');

        expect($content)->toContain('my-local-notes.md')
            ->and($content)->toContain('# custom section');

        foreach (CodeguardDirectoryInitializer::requiredEntries() as $entry) {
            expect($content)->toContain($entry);
        }
    } finally {
        codeguardInitCleanup($base);
    }
});

it('does not duplicate canonical entries that already exist in .gitignore', function (): void {
    $base = codeguardInitTempDir();
    $dir = $base.DIRECTORY_SEPARATOR.'.codeguard';
    mkdir($dir, 0o755);

    $existing = implode("\n", CodeguardDirectoryInitializer::requiredEntries())."\n";
    file_put_contents($dir.DIRECTORY_SEPARATOR.'.gitignore', $existing);
    $beforeHash = hash_file('sha256', $dir.DIRECTORY_SEPARATOR.'.gitignore');

    try {
        $initializer = new CodeguardDirectoryInitializer(new Filesystem, $base);
        $initializer->initialize();

        $content = file_get_contents($dir.DIRECTORY_SEPARATOR.'.gitignore');

        foreach (CodeguardDirectoryInitializer::requiredEntries() as $entry) {
            expect(substr_count($content, $entry))->toBe(1);
        }

        $afterHash = hash_file('sha256', $dir.DIRECTORY_SEPARATOR.'.gitignore');
        expect($afterHash)->toBe($beforeHash);
    } finally {
        codeguardInitCleanup($base);
    }
});

it('is idempotent across consecutive calls', function (): void {
    $base = codeguardInitTempDir();

    try {
        $initializer = new CodeguardDirectoryInitializer(new Filesystem, $base);
        $initializer->initialize();
        $firstRun = file_get_contents($base.'/.codeguard/.gitignore');

        $initializer->initialize();
        $secondRun = file_get_contents($base.'/.codeguard/.gitignore');

        expect($secondRun)->toBe($firstRun);
    } finally {
        codeguardInitCleanup($base);
    }
});

it('exposes a stable canonical-entries list (telemetry + baseline + decisions)', function (): void {
    $entries = CodeguardDirectoryInitializer::requiredEntries();

    expect($entries)->toContain('telemetry.jsonl')
        ->and($entries)->toContain('telemetry-*.jsonl')
        ->and($entries)->toContain('baseline.json')
        ->and($entries)->toContain('phpstan-extensions.yaml')
        ->and($entries)->toContain('layer-decisions.yaml');
});
