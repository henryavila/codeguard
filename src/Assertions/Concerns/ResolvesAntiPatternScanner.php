<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Assertions\Concerns;

use Henryavila\Codeguard\Assertions\AntiPatternScanner;

/**
 * Shared scanner construction + violation formatting for the assertion
 * traits. Tests override {@see makeAntiPatternScanner()} to point the
 * scanner at a fixture base path instead of the live project root.
 */
trait ResolvesAntiPatternScanner
{
    protected function makeAntiPatternScanner(): AntiPatternScanner
    {
        return new AntiPatternScanner(base_path());
    }

    /**
     * @param  list<string>  $violations
     */
    protected function formatAntiPatternViolations(string $headline, array $violations): string
    {
        return $headline."\n".implode("\n", $violations);
    }
}
