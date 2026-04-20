<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

enum LayerOption: string
{
    case Domain = 'Domain';
    case Application = 'Application';
    case Presentation = 'Presentation';
    case Infrastructure = 'Infrastructure';
    case Skip = '__skip__';
    case Custom = '__custom__';

    public function description(): string
    {
        return match ($this) {
            self::Domain => 'pure business rules, value objects (no framework, no DB, no HTTP)',
            self::Application => 'orchestration: services, jobs, events, notifications, observers',
            self::Presentation => 'entry points: HTTP, Livewire, Filament, Nova, Console',
            self::Infrastructure => 'DB models, repositories, external API clients',
            self::Skip => 'cross-cutting code, called by ALL layers (not in ruleset)',
            self::Custom => 'define a new layer name (e.g., Integration, Reporting)',
        };
    }

    public function example(): string
    {
        return match ($this) {
            self::Domain => 'App\\Domain\\Subscription, App\\ValueObjects\\Money, App\\Policies\\ArticlePolicy',
            self::Application => 'App\\Services\\CheckoutService, App\\Jobs\\SendInvoice, App\\Events\\OrderPlaced',
            self::Presentation => 'App\\Http\\Controllers\\*, App\\Livewire\\*, App\\Filament\\Resources\\*',
            self::Infrastructure => 'App\\Models\\User (Eloquent), App\\Repositories\\OrderRepository',
            self::Skip => 'App\\Providers\\*, App\\Exceptions\\*, App\\Traits\\*, App\\Helpers\\*',
            self::Custom => 'App\\Integration\\*, App\\Reporting\\* (project-specific bounded context)',
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
        return in_array($this, [
            self::Domain,
            self::Application,
            self::Presentation,
            self::Infrastructure,
        ], strict: true);
    }

    /**
     * Extended guidance shown as a hint beneath the select prompt when
     * Skip is auto-suggested for a bootstrap/cross-cutting namespace.
     */
    public static function skipGuidance(): string
    {
        return <<<'TEXT'
When to use Skip:
Code legitimately called by multiple layers at once. Classifying it into a
single layer would block its real usage pattern.

Typical skip-worthy namespaces:
  • Providers  → bootstrap: register routes, policies, events, bindings
                 across every layer (this is their job)
  • Exceptions → thrown from Domain, caught in Presentation/Application
  • Traits     → mixins reused by models, services, controllers
  • Helpers    → global utilities (arrays, strings, formatting)

Impact of classifying instead of skipping:
Deptrac will flag legitimate references as violations (e.g. a Provider
registering a Livewire component would violate Application → Presentation,
producing many false positives).

You can still move it to a layer later by editing deptrac.yaml manually.
TEXT;
    }

    /**
     * Short per-layer "what belongs here" copy shown as hint when the
     * heuristic pre-suggests this layer for a namespace.
     */
    public function typicalHint(string $namespace): string
    {
        return match ($this) {
            self::Domain => "{$namespace}\\* typically = Domain: pure business logic, no framework.",
            self::Application => "{$namespace}\\* typically = Application: orchestrates Domain, called by Presentation.",
            self::Presentation => "{$namespace}\\* typically = Presentation: UI/transport layer calling Application services.",
            self::Infrastructure => "{$namespace}\\* typically = Infrastructure: adapter to DB / external systems.",
            self::Skip => "{$namespace}\\* is cross-cutting — see skip guidance below.",
            self::Custom => "{$namespace}\\* — define a custom bounded context.",
        };
    }

    /**
     * Layers that may be used as targets for wizard assignment
     * (excludes sentinel options Skip / Custom).
     *
     * @return list<self>
     */
    public static function concreteLayers(): array
    {
        return [self::Domain, self::Application, self::Presentation, self::Infrastructure];
    }
}
