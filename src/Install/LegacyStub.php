<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

final readonly class LegacyStub
{
    public function __construct(
        public string $path,
        public string $replacement,
        public string $reason,
    ) {}
}
