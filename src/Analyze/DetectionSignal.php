<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

/**
 * One `detection.signals[]` entry from a pattern YAML — used by
 * {@see PatternMatcher} to pre-filter which files a pattern applies to.
 *
 * `type` is `file` (glob), `directory` (path prefix), or `import`
 * (namespace glob, approximated as a PSR-4 path prefix in the MVP).
 */
final readonly class DetectionSignal
{
    public function __construct(
        public string $type,
        public string $value,
    ) {}
}
