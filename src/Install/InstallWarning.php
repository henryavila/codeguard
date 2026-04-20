<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

final readonly class InstallWarning
{
    public function __construct(
        public WarningLevel $level,
        public WarningCode $code,
        public string $message,
        public string $remediation,
    ) {}
}
