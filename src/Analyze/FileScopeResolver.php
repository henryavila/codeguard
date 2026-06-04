<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

use Henryavila\Codeguard\Testing\CommandExecutor;
use Symfony\Component\Finder\Finder;

/**
 * Resolves which files `codeguard:analyze` reviews:
 *  - changedOnly() — git-changed + staged .php files (the default scope)
 *  - path()        — a single file or subtree
 *  - all()         — every .php file under the working directory
 *
 * Git is invoked through {@see CommandExecutor} so the changed-only path is
 * testable with a fake executor (no real repo required). Returns absolute
 * paths to existing .php files.
 */
final class FileScopeResolver
{
    /**
     * Directories never reviewed by a broad scan — dependencies and generated
     * output. Excluded relative to each scanned root, so an explicit
     * `--path=vendor/foo` still works; only `--all` / directory scans skip them.
     *
     * @var list<string>
     */
    private const EXCLUDED_DIRS = ['vendor', 'node_modules', 'storage', 'bootstrap/cache'];

    public function __construct(
        private readonly CommandExecutor $executor,
        private readonly string $workingDirectory,
    ) {}

    /**
     * @return list<string>
     */
    public function changedOnly(): array
    {
        $files = array_merge(
            $this->gitFiles(['git', 'diff', '--name-only', '--diff-filter=ACMR', 'HEAD']),
            $this->gitFiles(['git', 'diff', '--name-only', '--diff-filter=ACMR', '--cached']),
        );

        return $this->toExistingPhpFiles($files);
    }

    /**
     * @return list<string>
     */
    public function path(string $path): array
    {
        $absolute = $this->absolute($path);

        if (is_file($absolute)) {
            return str_ends_with($absolute, '.php') ? [$absolute] : [];
        }

        if (! is_dir($absolute)) {
            return [];
        }

        return $this->phpFilesIn($absolute);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        if (! is_dir($this->workingDirectory)) {
            return [];
        }

        return $this->phpFilesIn($this->workingDirectory);
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function gitFiles(array $command): array
    {
        $buffer = '';
        $this->executor->run($command, function (string $chunk) use (&$buffer): void {
            $buffer .= $chunk;
        });

        $lines = preg_split('/\R/', trim($buffer)) ?: [];

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function toExistingPhpFiles(array $files): array
    {
        $unique = [];
        foreach ($files as $file) {
            if (! str_ends_with($file, '.php')) {
                continue;
            }

            $absolute = $this->absolute($file);
            if (is_file($absolute)) {
                $unique[$absolute] = true;
            }
        }

        return array_keys($unique);
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $dir): array
    {
        $files = [];
        $finder = Finder::create()->files()->in($dir)->name('*.php')->exclude(self::EXCLUDED_DIRS)->sortByName();
        foreach ($finder as $file) {
            $files[] = $file->getRealPath() ?: $file->getPathname();
        }

        return $files;
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $this->workingDirectory.DIRECTORY_SEPARATOR.$path;
    }
}
