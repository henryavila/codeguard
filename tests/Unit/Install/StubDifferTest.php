<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\StubDiffer;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-differ-'.uniqid();
    mkdir($this->tempDir, 0o755, true);
});

afterEach(function (): void {
    $entries = glob($this->tempDir.'/*') ?: [];
    foreach ($entries as $entry) {
        if (is_file($entry)) {
            unlink($entry);
        }
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

it('returns null when both files have identical content', function (): void {
    $existing = $this->tempDir.'/existing.txt';
    $incoming = $this->tempDir.'/incoming.txt';

    file_put_contents($existing, "line 1\nline 2\n");
    file_put_contents($incoming, "line 1\nline 2\n");

    $differ = new StubDiffer(new Filesystem());

    expect($differ->diff($existing, $incoming))->toBeNull();
});

it('returns null when one of the files does not exist', function (): void {
    $existing = $this->tempDir.'/real.txt';
    file_put_contents($existing, "content\n");

    $differ = new StubDiffer(new Filesystem());

    expect($differ->diff($existing, $this->tempDir.'/missing.txt'))->toBeNull();
});

it('returns a unified diff with header when files differ', function (): void {
    $existing = $this->tempDir.'/existing.txt';
    $incoming = $this->tempDir.'/incoming.txt';

    file_put_contents($existing, "line 1\nline 2\n");
    file_put_contents($incoming, "line 1\nchanged\n");

    $diff = (new StubDiffer(new Filesystem()))->diff($existing, $incoming);

    expect($diff)->toBeString()
        ->and($diff)->toContain('--- existing.txt (existing)')
        ->and($diff)->toContain('+++ incoming.txt (stub)');
});

it('colorize wraps +/-/@@ lines with ANSI tags and leaves context alone', function (): void {
    $diff = implode("\n", [
        '--- old.txt (existing)',
        '+++ new.txt (stub)',
        '@@ -1,3 +1,3 @@',
        ' line 1',
        '-line 2',
        '+line changed',
        ' line 3',
    ]);

    $colored = (new StubDiffer(new Filesystem()))->colorize($diff);

    expect($colored)->toContain('<fg=yellow>--- old.txt (existing)</>')
        ->and($colored)->toContain('<fg=yellow>+++ new.txt (stub)</>')
        ->and($colored)->toContain('<fg=cyan>@@ -1,3 +1,3 @@</>')
        ->and($colored)->toContain('<fg=red>-line 2</>')
        ->and($colored)->toContain('<fg=green>+line changed</>')
        ->and($colored)->toContain(' line 1');
});

it('colorize preserves empty lines unchanged', function (): void {
    $colored = (new StubDiffer(new Filesystem()))->colorize("line\n\nother");

    expect($colored)->toBe("line\n\nother");
});
