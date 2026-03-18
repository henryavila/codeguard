# Laravel Quality Tools — Deep Research

**Date:** 2026-03-18
**Author:** Henry + Claude (Superpowers Brainstorming)
**Status:** Approved during brainstorming session

---

## Purpose

Comprehensive research on the Laravel quality tool ecosystem to inform CodeGuard's capabilities model, module design, and tool adapter implementation.

---

## Tool Map

```
                    CodeGuard Capabilities
                           │
    ┌──────────┬───────────┼───────────┬──────────────┐
    │          │           │           │              │
formatting  static     mess       arch          ai-review
    │       analysis   detection  testing          │
    │          │           │           │              │
   Pint    Larastan     PHPMD      Pest         CodeGuard
    │      (PHPStan)       │      (arch)        (AI agent)
    │          │           │           │              │
    └──────────┴───────────┴───────────┴──────────────┘
                           │
                    + Rector (modernization, roadmap)
                    + Enlightn (120+ checks, roadmap)
                    + Infection (mutation testing, roadmap)
```

---

## 1. Pint — Formatting

**What:** Opinionated PHP code style fixer, built on PHP CS Fixer.
**Maintainer:** Laravel (official)
**Install:** `composer require --dev laravel/pint`

### Key Characteristics
- **Autofix** — corrects code, never just reports
- **Zero-config** — works out of the box with Laravel preset
- **Non-blocking** — formatting is a fix, not a gate

### Presets

| Preset | Description |
|---|---|
| `laravel` | Default — Laravel's opinionated style |
| `psr12` | PSR-12 compliance |
| `symfony` | Symfony style conventions |

### Config
`pint.json` at project root (optional):
```json
{
    "preset": "laravel",
    "rules": {
        "concat_space": {
            "spacing": "one"
        }
    },
    "exclude": ["vendor"]
}
```

### CodeGuard Integration
- **Capability:** `formatting`
- **Enforcement:** `autofix` (always fix, never block)
- **Hook behavior:** Run `vendor/bin/pint` on staged files before commit — auto-corrects and re-stages
- **Adapter:** Simple — `pint --test` for check, `pint` for fix
- **Output:** Pint returns exit code 0 (clean) or 1 (has changes) with list of changed files

### Sources
- [Laravel Pint Docs](https://laravel.com/docs/12.x/pint)
- [GitHub](https://github.com/laravel/pint)

---

## 2. Larastan — Static Analysis

**What:** PHPStan + Laravel intelligence. Understands Facades, Eloquent, Service Container, Collections.
**Maintainer:** Community (Nuno Maduro / Larastan team)
**Install:** `composer require --dev larastan/larastan`

### IMPORTANT
For Laravel projects, Larastan **REPLACES** PHPStan. They do NOT run together. Larastan IS PHPStan with the Laravel extension included.

### Levels (0-9)

| Level | What it adds |
|---|---|
| 0 | Basic errors, undefined classes/functions |
| 1 | Possibly undefined return values |
| 2 | Undefined methods on `mixed` |
| 3 | Return types verified |
| 4 | Dead code (unreachable types) |
| 5 | Function argument types verified |
| 6 | Missing type hints reported |
| 7 | Partial union types verified |
| 8 | Methods on nullable without null check |
| 9 | Everything strict — `mixed` not allowed |

### What it detects (examples)
- Calling undefined methods
- Incorrect parameter types
- Accessing non-existent properties
- Wrong return types
- Type mismatches in assignments
- Unreachable code branches
- Missing null checks

### Laravel-specific understanding
- Facades (resolves to real classes)
- Eloquent model properties and relations
- Service Container bindings
- Collection methods and generics
- Form Request validated data types
- Route model binding types

### Config
`phpstan.neon` at project root:
```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app/
    level: 6
```

### Extra strict rules
Package: `canvural/larastan-strict-rules`
- Disallows dynamic where methods on Eloquent query builder
- Disallows usage of Laravel Facades (encourages DI)

### CodeGuard Integration
- **Capability:** `static-analysis`
- **Enforcement:** `block` or `warn` (configurable)
- **Hook behavior:** Run on FULL project, filter output to staged files only (PHPStan needs full project context for type inference)
- **Adapter:** Run `vendor/bin/phpstan analyse --error-format=json`, parse JSON, filter by staged file paths
- **Critical:** PHPStan on individual staged files gives DIFFERENT results than full-project — always run full, filter output

### Sources
- [Larastan GitHub](https://github.com/larastan/larastan)
- [Larastan Strict Rules](https://github.com/canvural/larastan-strict-rules)
- [From 0 to 9 with Larastan](https://laravel.io/articles/how-to-get-your-laravel-app-from-0-to-9-with-larastan)

---

## 3. Pest — Testing + Architecture Testing

**What:** Elegant PHP testing framework, built on PHPUnit. Key differentiator: Architecture Testing.
**Maintainer:** Nuno Maduro
**Install:** `composer require --dev pestphp/pest`

### Architecture Testing — The Differentiator

Pest allows writing structural rules as tests that run in CI/hooks. These are **deterministic** — 100% precise, no false positives.

### All Available Expectations

**Type checking:**
- `toBeClasses()`, `toBeInterfaces()`, `toBeTraits()`, `toBeEnums()`
- `toBeAbstract()`, `toBeFinal()`, `toBeReadonly()`, `toBeInvokable()`
- `toBeIntBackedEnums()`, `toBeStringBackedEnums()`

**Inheritance & Implementation:**
- `toExtend()`, `toExtendNothing()`
- `toImplement()`, `toImplementNothing()`, `toOnlyImplement()`
- `toUseTrait()`, `toUseTraits()`

**Dependencies & Usage (most important for CodeGuard):**
- `toUse()` — with `not`, prevents specific class/function usage
- `toOnlyUse()` — restricts dependencies to specified namespaces
- `toBeUsed()` — with `not`, ensures code isn't used
- `toBeUsedIn()` — with `not`, prevents usage in specific namespace
- `toOnlyBeUsedIn()` — limits usage to particular sections
- `toUseNothing()` — no external dependencies

**Code quality:**
- `toHaveLineCountLessThan()` — maximum line limits
- `toUseStrictTypes()` — strict types everywhere
- `toUseStrictEquality()` — `===` instead of `==`

**Methods & Structure:**
- `toHaveMethod()`, `toHaveMethods()`
- `toHavePublicMethodsBesides()` — allow only specific public methods
- `toHaveConstructor()`, `toHaveDestructor()`

**Naming:**
- `toHavePrefix()`, `toHaveSuffix()`

**Modifiers:**
- `ignoring()` — exclude specific namespaces/classes
- `classes()`, `enums()`, `interfaces()`, `traits()` — target specific types
- `extending()`, `implementing()`, `using()` — target by relationship

### Built-in Presets

| Preset | What it enforces |
|---|---|
| `php` | No `die`, `var_dump`, deprecated functions |
| `security` | No `eval`, `md5`, `sha1`, unsafe `unserialize` |
| `laravel` | Controllers REST-only methods, proper suffixes, directory structure |
| `strict` | Strict types everywhere, final classes, complete documentation |
| `relaxed` | Permissive version of strict |

### Example Architecture Tests

```php
// Controllers must have suffix Controller
arch()->expect('App\Http\Controllers')
    ->toHaveSuffix('Controller');

// Services can only use repos, DTOs, events
arch()->expect('App\Services')
    ->toOnlyUse(['App\Repositories', 'App\DTOs', 'App\Events', 'App\Exceptions']);

// Models only used by repositories
arch()->expect('App\Models')
    ->toOnlyBeUsedIn('App\Repositories');

// No debug functions anywhere
arch()->expect('App')->not->toUse(['dd', 'dump', 'var_dump']);

// Strict types project-wide
arch()->expect('App')->toUseStrictTypes();

// Domain events must be readonly
arch()->expect('App\Domain\Events')
    ->toBeClasses()
    ->toBeReadonly();

// Apply presets
arch()->preset()->laravel();
arch()->preset()->security();
```

### Pest vs AI — Complementary

| Check | Pest (deterministic) | CodeGuard AI (probabilistic) |
|---|---|---|
| "Controllers must have suffix Controller" | `toHaveSuffix('Controller')` — 100% | AI can check, but overkill |
| "Services must not access Http" | `toOnlyUse(...)` — 100% | AI can check, but Pest is better |
| "Business logic in Controller" | Cannot detect (semantic) | AI analyzes semantics — only it can |
| "DTO should be used here" | Cannot detect (context-dependent) | AI understands context — only it can |

### CodeGuard Integration
- **Capability:** `arch-testing`
- **Enforcement:** `block` (architectural rules should block)
- **During setup:** CodeGuard generates `tests/Architecture/CodeGuardArchTest.php` with arch() expectations based on active patterns
- **Hook behavior:** Run `vendor/bin/pest --filter=Architecture` as part of pre-commit
- **Adapter:** Run Pest, parse output (JUnit XML or text)
- **Key insight:** Structural rules → Pest (deterministic). Semantic rules → AI (probabilistic). Both are needed.

### Sources
- [Pest Arch Testing Docs](https://pestphp.com/docs/arch-testing)
- [10 Powerful Arch Tests for Laravel](https://www.fuseweb.nl/en/blog/2025/02/07/10-powerful-pestphp-arch-tests-laravel)
- [Pest 3 Architecture Presets](https://benjamincrozat.com/pest-3-architecture-testing-presets)
- [Architecture Testing in Laravel — Freek Van der Herten](https://freek.dev/2710-architecture-testing-in-laravel-with-pest)

---

## 4. PHPMD — Mess Detection

**What:** Detects code smells — complexity, dead code, naming issues.
**Install:** `composer require --dev phpmd/phpmd`

### Rulesets

| Ruleset | What it detects |
|---|---|
| `cleancode` | Static access, else expressions, boolean args |
| `codesize` | Cyclomatic complexity, long methods, large classes |
| `controversial` | Naming (camelCase), superglobals — generates noise, avoid |
| `design` | Coupling, inheritance depth, number of children |
| `naming` | Short variables, long methods names |
| `unusedcode` | Unused parameters, dead variables |

### CodeGuard Integration
- **Capability:** `mess-detection`
- **Enforcement:** `warn` (rarely blocking)
- **MVP rulesets:** `unusedcode` + `codesize`
- **Avoid:** `controversial` ruleset (too noisy)
- **Adapter:** Run `vendor/bin/phpmd [files] json [rulesets]`, parse JSON output

---

## 5. Rector — Modernization (Roadmap)

**What:** Automated PHP/Laravel refactoring tool.
**Install:** `composer require --dev rector/rector rector/rector-laravel`

### Key Rule Sets

| Set | What it does |
|---|---|
| `LaravelSetList::LARAVEL_120` | Upgrade rules for Laravel 12 |
| `LARAVEL_TYPE_DECLARATIONS` | Add type hints and generics |
| `LARAVEL_IF_HELPERS` | `if(x) abort()` → `abort_if(x)` |
| `LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL` | Helpers → `Str::` / `Arr::` |

### CodeGuard Integration (Roadmap)
- **Capability:** `modernization`
- **Enforcement:** `autofix` (transforms code)
- **Value:** `/codeguard-run` could offer: "Found 45 legacy helpers. Want Rector to modernize?"

### Sources
- [Rector Laravel GitHub](https://github.com/driftingly/rector-laravel)
- [Rector PHP](https://github.com/rectorphp/rector)

---

## Summary: MVP Capabilities

| Capability | Tool | Enforcement | Hook? |
|---|---|---|---|
| `formatting` | Pint | Autofix | Yes — auto-corrects staged files |
| `static-analysis` | Larastan | Block/Warn | Yes — full project, filter to staged |
| `mess-detection` | PHPMD | Warn | Yes — staged files only |
| `arch-testing` | Pest | Block | Yes — run arch test suite |
| `ai-review` | CodeGuard AI | Block/Warn | No — only via `/codeguard-run` skill |
