<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

class EnvironmentDetector
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $basePath,
    ) {}

    public function detect(): EnvironmentInfo
    {
        return new EnvironmentInfo(
            phpVersion: PHP_VERSION,
            composerVersion: $this->detectComposerVersion(),
            nodeVersion: $this->detectBinaryVersion('node', ['--version']),
            hasPackageJson: $this->filesystem->exists($this->basePath.'/package.json'),
            hasNodeModules: $this->filesystem->isDirectory($this->basePath.'/node_modules'),
            hasLefthookBinary: $this->detectBinaryVersion('lefthook', ['version']) !== null,
        );
    }

    private function detectComposerVersion(): string
    {
        if (class_exists(InstalledVersions::class)) {
            $version = InstalledVersions::getRootPackage()['pretty_version'] ?? null;
            if (is_string($version)) {
                return $version;
            }
        }

        $fallback = $this->detectBinaryVersion('composer', ['--version']);

        return $fallback ?? 'unknown';
    }

    /**
     * @param  list<string>  $arguments
     */
    private function detectBinaryVersion(string $binary, array $arguments): ?string
    {
        try {
            $process = new Process([$binary, ...$arguments]);
            $process->setTimeout(5);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $output = trim($process->getOutput());

            if ($output === '') {
                return null;
            }

            return $this->parseVersion($output);
        } catch (ExceptionInterface) {
            return null;
        }
    }

    private function parseVersion(string $output): string
    {
        if (preg_match('/\bv?(\d+\.\d+(?:\.\d+)?)\b/', $output, $matches) === 1) {
            return $matches[1];
        }

        $firstLine = strtok($output, "\n");

        return $firstLine !== false ? $firstLine : $output;
    }
}
