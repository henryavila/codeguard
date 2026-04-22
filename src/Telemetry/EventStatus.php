<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Telemetry;

enum EventStatus: string
{
    case Ok = 'ok';
    case Fail = 'fail';
    case Skip = 'skip';
}
