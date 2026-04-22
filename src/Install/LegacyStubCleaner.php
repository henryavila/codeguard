<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Illuminate\Filesystem\Filesystem;

/**
 * Detects stub files that CodeGuard used to publish in a previous era but no
 * longer owns. Offered for removal during `codeguard:install` so projects
 * don't carry dead config alongside its replacement (e.g., lefthook.yml from
 * the pre-CaptainHook era, per ADR-010).
 *
 * To retire a file, add a LegacyStub entry — the installer will offer to
 * delete it on the next run. Never auto-delete: the user owns their tree.
 */
final class LegacyStubCleaner
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $basePath,
    ) {}

    /**
     * @return list<LegacyStub>
     */
    public function detect(): array
    {
        $detected = [];

        foreach ($this->legacyStubs() as $stub) {
            if ($this->filesystem->exists($this->absolutePath($stub->path))) {
                $detected[] = $stub;
            }
        }

        return $detected;
    }

    public function delete(LegacyStub $stub): bool
    {
        $path = $this->absolutePath($stub->path);

        if (! $this->filesystem->exists($path)) {
            return false;
        }

        return $this->filesystem->delete($path);
    }

    public function absolutePath(string $relativePath): string
    {
        return $this->basePath.DIRECTORY_SEPARATOR.$relativePath;
    }

    /**
     * @return list<LegacyStub>
     */
    public function legacyStubs(): array
    {
        return [
            new LegacyStub(
                path: 'lefthook.yml',
                replacement: 'captainhook.json',
                reason: 'Pre-CaptainHook era (ADR-010). Left behind, it runs ghost hooks on commit.',
            ),
        ];
    }
}
