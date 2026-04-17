<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Testing;

enum Preset: string
{
    case Default = 'codeguard';
    case Full = 'codeguard-full';

    public function requiresNode(): bool
    {
        return $this === self::Full;
    }

    public function label(): string
    {
        return match ($this) {
            self::Default => 'codeguard (PHP-native, 5 gates)',
            self::Full => 'codeguard-full (includes Node-based jscpd, 8 gates)',
        };
    }

    /**
     * @return list<string>
     */
    public function enabledGateKeys(): array
    {
        return match ($this) {
            self::Default => ['audit', 'pint', 'phpstan', 'deptrac', 'infection'],
            self::Full => ['audit', 'pint', 'phpstan', 'deptrac', 'infection', 'jscpd', 'insights'],
        };
    }
}
