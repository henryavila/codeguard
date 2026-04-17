<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

final readonly class DetectedNamespace
{
    public function __construct(
        public string $relativePath,
        public string $namespace,
        public int $fileCount,
        public ?string $suggestedLayer,
    ) {}
}
