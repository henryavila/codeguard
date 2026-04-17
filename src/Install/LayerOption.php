<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

enum LayerOption: string
{
    case Domain = 'Domain';
    case Application = 'Application';
    case Persistence = 'Persistence';
    case Skip = '__skip__';
    case Custom = '__custom__';

    public function description(): string
    {
        return match ($this) {
            self::Domain => 'business rules, entities, value objects',
            self::Application => 'orchestration, services, controllers, jobs',
            self::Persistence => 'data access, repositories, ORM models',
            self::Skip => 'not included in Deptrac ruleset (no layer enforcement)',
            self::Custom => 'define a new layer name (e.g., Integration, Console)',
        };
    }

    public function example(): string
    {
        return match ($this) {
            self::Domain => 'App\\Domain\\Subscription (pure business invariants)',
            self::Application => 'App\\Services\\CheckoutService (orchestrates Domain + Persistence)',
            self::Persistence => 'App\\Models\\User (Eloquent model, migrations, queries)',
            self::Skip => 'App\\Features (feature flags — not part of layered architecture)',
            self::Custom => 'App\\Integration\\* (third-party API adapters as their own layer)',
        };
    }

    public function promptLabel(): string
    {
        return $this === self::Skip || $this === self::Custom
            ? $this->displayName().' — '.$this->description()
            : $this->value.' — '.$this->description();
    }

    public function displayName(): string
    {
        return match ($this) {
            self::Skip => 'Skip',
            self::Custom => 'Custom layer…',
            default => $this->value,
        };
    }

    public function isBuiltIn(): bool
    {
        return in_array($this, [self::Domain, self::Application, self::Persistence], strict: true);
    }
}
