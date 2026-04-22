<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

enum CaptainhookInstallStatus: string
{
    case Installed = 'installed';
    case BinaryMissing = 'binary-missing';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
