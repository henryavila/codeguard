<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\FileScopeResolver;
use Henryavila\Codeguard\Tests\Support\FakeCommandExecutor;

function fsrBase(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-fsr-'.uniqid();
    mkdir($dir.'/src', 0o755, true);

    return $dir;
}

function fsrWrite(string $base, string $relative): void
{
    $path = $base.DIRECTORY_SEPARATOR.$relative;
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }
    file_put_contents($path, "<?php\n");
}

function fsrCleanup(string $base): void
{
    if (! is_dir($base)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($base);
}

function fsrExecutor(string $gitOutput): FakeCommandExecutor
{
    return new FakeCommandExecutor(fn (array $command): array => [0, $gitOutput]);
}

it('returns changed + staged php files that exist on disk', function (): void {
    $base = fsrBase();

    try {
        fsrWrite($base, 'src/Foo.php');
        fsrWrite($base, 'src/Bar.php');

        $resolver = new FileScopeResolver(
            fsrExecutor("src/Foo.php\nsrc/Bar.php\nREADME.md\nsrc/Gone.php\n"),
            $base,
        );

        $files = $resolver->changedOnly();

        expect($files)->toHaveCount(2)
            ->and($files)->toContain($base.DIRECTORY_SEPARATOR.'src/Foo.php');
    } finally {
        fsrCleanup($base);
    }
});

it('resolves a single file path', function (): void {
    $base = fsrBase();

    try {
        fsrWrite($base, 'src/Foo.php');
        $resolver = new FileScopeResolver(fsrExecutor(''), $base);

        $files = $resolver->path('src/Foo.php');

        expect($files)->toHaveCount(1)
            ->and($files[0])->toContain('Foo.php');
    } finally {
        fsrCleanup($base);
    }
});

it('resolves a directory subtree to its php files only', function (): void {
    $base = fsrBase();

    try {
        fsrWrite($base, 'src/Foo.php');
        fsrWrite($base, 'src/Bar.php');
        fsrWrite($base, 'src/notes.txt');
        $resolver = new FileScopeResolver(fsrExecutor(''), $base);

        expect($resolver->path('src'))->toHaveCount(2);
    } finally {
        fsrCleanup($base);
    }
});

it('returns empty for a non-existent path', function (): void {
    $base = fsrBase();

    try {
        $resolver = new FileScopeResolver(fsrExecutor(''), $base);

        expect($resolver->path('does/not/exist'))->toBe([]);
    } finally {
        fsrCleanup($base);
    }
});

it('returns every php file under the working directory for --all', function (): void {
    $base = fsrBase();

    try {
        fsrWrite($base, 'src/Foo.php');
        fsrWrite($base, 'app/Bar.php');
        $resolver = new FileScopeResolver(fsrExecutor(''), $base);

        expect($resolver->all())->toHaveCount(2);
    } finally {
        fsrCleanup($base);
    }
});
