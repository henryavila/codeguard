<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

/**
 * PR-oriented action taxonomy for analyze findings (skill-value uplift Phase B).
 *
 * block          — do not merge until fixed
 * request_change — should be addressed before merge
 * info           — informational; does not block the PR decision
 */
enum FindingAction: string
{
    case Block = 'block';
    case RequestChange = 'request_change';
    case Info = 'info';

    public function sectionTitle(): string
    {
        return match ($this) {
            self::Block => 'BLOCK',
            self::RequestChange => 'REQUEST CHANGE',
            self::Info => 'INFO',
        };
    }
}
