<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

final readonly class CaptainhookInstallResult
{
    public function __construct(
        public CaptainhookInstallStatus $status,
        public ?string $message = null,
    ) {}
}
