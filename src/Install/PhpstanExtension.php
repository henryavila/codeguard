<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

enum PhpstanExtension: string
{
    case Larastan = 'larastan';
    case PhpUnit = 'phpunit';
    case Peststan = 'peststan';
    case CognitiveComplexity = 'cognitive-complexity';
    case DeadCode = 'dead-code';
    case DisallowedCalls = 'disallowed-calls';
    case TestQuality = 'test-quality';

    public function displayName(): string
    {
        return match ($this) {
            self::Larastan => 'Larastan',
            self::PhpUnit => 'PHPStan-PHPUnit',
            self::Peststan => 'Peststan',
            self::CognitiveComplexity => 'Cognitive Complexity',
            self::DeadCode => 'Dead Code Detector',
            self::DisallowedCalls => 'Disallowed Calls',
            self::TestQuality => 'Test Quality Kit',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Larastan => 'Laravel-aware PHPStan (facades, Eloquent magic, route binding)',
            self::PhpUnit => 'PHPUnit rules — required for Pest (runs on top of PHPUnit)',
            self::Peststan => '$this resolution inside Pest closures (opt-in; auto-selected when Pest is present)',
            self::CognitiveComplexity => 'Sonar-style cognitive complexity metrics per class/method',
            self::DeadCode => 'Unused methods/constants/properties (Laravel provider enabled)',
            self::DisallowedCalls => 'Bans dd(), dump(), var_dump(), die() in production code',
            self::TestQuality => 'Laravel test anti-patterns (rules() directly, toSql assertions)',
        };
    }

    public function isDependOn(): ?self
    {
        return match ($this) {
            self::TestQuality => self::DisallowedCalls,
            default => null,
        };
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Baseline set used when nothing has been saved yet AND auto-detection
     * has no extra signal to act on. Peststan is excluded on purpose — it's
     * only enabled when Pest is detected in the consumer's composer.json.
     *
     * @return list<self>
     */
    public static function defaultEnabled(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $ext): bool => $ext !== self::Peststan,
        ));
    }
}
