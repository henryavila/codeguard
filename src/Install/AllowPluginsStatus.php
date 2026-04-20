<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

enum AllowPluginsStatus: string
{
    case Allowed = 'allowed';
    case NotAllowed = 'not-allowed';
    case Unknown = 'unknown';
}
