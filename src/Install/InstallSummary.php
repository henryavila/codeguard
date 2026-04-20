<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

/**
 * Aggregates warnings raised during `codeguard:install` so the final block
 * of the command output can surface pendencies instead of burying them.
 *
 * Scope is per-command-run: a fresh instance is created at the top of
 * `CodeguardInstallCommand::handle()` and discarded when the command ends.
 * Do not register as a singleton.
 */
final class InstallSummary
{
    /** @var list<InstallWarning> */
    private array $warnings = [];

    public function warn(InstallWarning $warning): void
    {
        $this->warnings[] = $warning;
    }

    public function isEmpty(): bool
    {
        return $this->warnings === [];
    }

    public function hasIssues(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * @return list<InstallWarning>
     */
    public function warnings(): array
    {
        $sorted = $this->warnings;

        // PHP 8.0+ usort is stable — insertion order is preserved within
        // equal-severity groups, which the renderer relies on.
        usort(
            $sorted,
            static fn (InstallWarning $a, InstallWarning $b): int => self::severityWeight($b->level) <=> self::severityWeight($a->level),
        );

        return $sorted;
    }

    public function highestLevel(): ?WarningLevel
    {
        $highest = null;

        foreach ($this->warnings as $warning) {
            if ($highest === null || self::severityWeight($warning->level) > self::severityWeight($highest)) {
                $highest = $warning->level;
            }
        }

        return $highest;
    }

    private static function severityWeight(WarningLevel $level): int
    {
        return match ($level) {
            WarningLevel::Error => 2,
            WarningLevel::Warning => 1,
        };
    }
}
