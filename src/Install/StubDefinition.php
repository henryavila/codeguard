<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

final readonly class StubDefinition
{
    public function __construct(
        public string $stubRelativePath,
        public string $targetRelativePath,
        public string $gateName,
    ) {}
}
