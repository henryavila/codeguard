<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

enum WarningLevel: string
{
    case Warning = 'warning';
    case Error = 'error';
}
