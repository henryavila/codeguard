<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

final readonly class EnvironmentInfo
{
    public function __construct(
        public string $phpVersion,
        public string $composerVersion,
        public ?string $nodeVersion,
        public bool $hasPackageJson,
        public bool $hasNodeModules,
        public bool $hasCaptainhookBinary,
        public bool $usesPest = false,
    ) {}

    public function hasNode(): bool
    {
        return $this->nodeVersion !== null;
    }

    public function usesNodeInProject(): bool
    {
        return $this->hasPackageJson || $this->hasNodeModules;
    }

    /**
     * Confidence for auto-selecting codeguard-full preset.
     *
     * high:   package.json or node_modules exist (project actively uses Node)
     * medium: only global `node` binary (project doesn't use Node yet)
     * low:    no Node anywhere
     */
    public function nodeConfidence(): string
    {
        return match (true) {
            $this->usesNodeInProject() => 'high',
            $this->hasNode() => 'medium',
            default => 'low',
        };
    }
}
