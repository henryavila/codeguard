# CodeGuard

> **Laravel quality gates that survive your AI agent.**

Consolidated install for Pint, PHPStan, Deptrac, Infection, and CaptainHook — with AI review where AST can't reach, multi-database schema dump, and honest best-effort Claude hooks.

**Status**: `0.2.0` is the first Packagist release. Pre-1.0 means the public API may shift; integrations should pin to a specific minor version (`^0.2`). Installs and runs on Laravel 11/12. Patterns engine, schema dump, and AI rules generator are roadmapped for later 0.x releases.

---

## Why CodeGuard

You have a Laravel project. You want:
- **PHPStan** catching type bugs
- **Deptrac** enforcing architecture boundaries
- **Infection** detecting `assertTrue(true)` type tests written by a contractor who doesn't use AI
- **CaptainHook** blocking bad commits before they hit CI (PHP-native, auto-activated by `composer install`)
- The same setup replicated across **every project** you own
- Easy upgrade path (`composer update`) to evolve the standard over time

You don't want to hand-configure 7 tools in each project, drift between repos, or pretend your `assertTrue(true)` tests work.

CodeGuard is a Composer package that installs, configures, and runs these gates cohesively.

---

## Quick Start

```bash
composer require --dev henryavila/codeguard
php artisan codeguard:install
```

The installer auto-detects your environment, recommends a preset, shows you exactly what will be installed (with honest config-time estimates), asks Deptrac layer questions scanned from your `app/` directory, and verifies CaptainHook is active (Composer's `hook-installer` plugin does the actual `.git/hooks/*` wiring).

```
CodeGuard — Laravel quality gates installer

Detecting environment...
  PHP                   8.5.5
  Composer              2.7.0
  Node.js               20.10.0
  package.json          found
  CaptainHook binary    available

Recommended preset ... codeguard-full ⭐
  • Project uses Node.js (package.json or node_modules detected).

=== Gates to install ===
  ✓ Pint             auto-format          config: 0         CI: ~5s
  ✓ PHPStan          type safety          config: 15min     CI: ~30s
  ✓ Deptrac          architecture         config: 30min     CI: ~15s
  ✓ Infection        test quality         config: 20min     CI: +3min
  ✓ CaptainHook      pre-commit enforce   config: 10min     CI: 0
  ✓ jscpd            duplication          config: 5min      CI: ~10s
  ✓ Insights         metrics              config: 0         CI: ~20s
  ✓ TestQualityTest  meta-quality         config: 15min     CI: ~5s

  Estimated total config ......... 1h 35min
  Estimated CI cost ........... ~4min per PR

Proceed with install? [yes]

Publishing stubs...
  pint.json                                 created
  phpstan.neon                              created
  phpstan-test-quality.neon                 created
  deptrac.yaml                              created
  infection.json5                           created
  captainhook.json                          created
  captainhook.json.README.md                created
  phpunit.xml                               created
  .github/workflows/codeguard-ci.yml        created
  .jscpd.json                               created
  tests/Arch/TestQualityTest.php            created

Deptrac layer detection
  app/Domain         (34 files)  → Domain
  app/Http           (12 files)  → Application
  app/Services       (8 files)   → Application
  app/Models         (15 files)  → Persistence
  app/Infrastructure (4 files)   → Persistence

  Layers: Domain, Application, Persistence
  Rules:
    Domain → (no dependencies allowed)
    Application → Domain
    Persistence → Domain

  [Use suggested layers]

  deptrac.yaml written with suggested layers

CaptainHook setup
  captainhook install   installed (.git/hooks registered)

Next steps:
  PHPStan     Review level in phpstan.neon (currently 5).
              → composer codeguard:check
  Deptrac     Verify layers in deptrac.yaml.
              → ./vendor/bin/deptrac analyse
  ...
```

---

## Presets

Two presets, auto-selected by the installer based on Node.js presence in your project.

| Preset | Tools | Requires Node? | Auto-selected when |
|--------|-------|:---:|--------------------|
| **`codeguard`** (default) | Pint + PHPStan + Deptrac + Infection + CaptainHook | ❌ | No `package.json` or `node_modules/` |
| **`codeguard-full`** | + jscpd + Insights + TestQualityTest | ✅ | `package.json` or `node_modules/` present |

**Philosophy**: no "Minimal" starter preset that gives false comfort. Both presets enforce the gates you actually need for a team project. The only real decision axis is whether you already have Node.js (and you probably do if you're running Vite/Vue).

**Override auto-selection**:

```bash
php artisan codeguard:install --preset=default      # force PHP-only
php artisan codeguard:install --preset=full         # force Node-included
php artisan codeguard:install --no-interactive      # CI mode
php artisan codeguard:install --refresh-stubs       # update stubs (diff-aware)
```

---

## Features

### ✅ Available in 0.2.0

- **`codeguard:install`** — guided hybrid install with smart stubs (inline educational comments), auto-detection of preset, Deptrac layer suggestion from your `app/` namespaces, CaptainHook auto-activated via Composer plugin, `phpunit.xml` strict-mode floor, and a minimal CI workflow stub
- **`codeguard:install --refresh-stubs`** — diff-aware re-run that lets you `keep`, `overwrite`, or review full diff per file. No silent customization loss.
- **`codeguard:install:override`** — register stub paths to keep across re-runs (`--detect` planned for 0.3.x)
- **`codeguard:check`** — runs all enabled gates sequentially with fail-fast and consolidated report. Layer 3 telemetry per-gate.
- **`codeguard:test`** — multi-stage test runner (Pest-first) driven by `StageConfig` in `config/codeguard.php`. Sequential or parallel stages, fail-fast modes, Layer 5 telemetry. Project-agnostic (no Playwright/MongoDB/Nova assumptions).
- **`codeguard:telemetry:enable|disable|clear`** — opt-in JSONL telemetry stored under `.codeguard/telemetry/` with field allowlist, rotation, and privacy-safe enums
- **`TestQualityAssertions`** + **`ParallelSafetyAssertions`** Pest traits — anti-pattern arch-tests (tautological assertions, Eloquent mocking, bare `assertNotNull`, `truncate` in tests, eager factory creates)
- **Auto-detect Node** — installer picks `codeguard` (PHP-only) or `codeguard-full` (+ jscpd + Insights + TestQualityTest) without asking
- **Honest estimates** — per-gate config time and CI cost shown before you commit

### 🚧 Roadmapped post-0.2.0

- `codeguard:analyze` — pattern engine (context-emit + `/codeguard-review`): `--focus=contractor`, `--base=origin/main`, work-order `scope` parity, BLOCK/REQUEST CHANGE/INFO checklist; hygiene excluded from `full` unless `--include-hygiene`
- `codeguard:prepare` — multi-database schema dump (MySQL, PostgreSQL, SQLite `:memory:`, SQL Server PDO fallback, Windows without `sqlite3` CLI)
- AI rules generator — emit canonical `.claude/rules/*.md` from `config('codeguard.ai_rules.targets')`
- Pest custom expectations beyond the two traits

### 🔮 Future

- `henryavila/codeguard-hooks` — Claude plugin for best-effort config-protection nudges (separate repo, install via `/plugin install`)
- Companion packages (`codeguard-symfony`, `codeguard-python`) if demand surfaces

---

## Philosophy

**Laravel-first, not language-agnostic.** We tried the "agnostic core" approach in v0.x (npm package). The core was a thin shell with 90% of real value being Laravel-specific. [Pivot rationale](docs/specs/2026-04-16-pivot-npm-to-composer.md).

**Best-effort Claude hooks, not hard enforcement.** Claude Code has [documented bypasses](https://github.com/anthropics/claude-code/issues/40117) for Edit/Write matchers (via Bash, MCP tools, `git commit --no-verify`). We do not claim what we cannot deliver. Hooks reduce friction for honest mistakes. **CI is the real gate.**

**Honest estimates, not marketing numbers.** When you see "config: 30min" for Deptrac, that's calibrated to real projects, not "2-4 hours" inflated to sound cautious.

**PHP-native core, Node opt-in.** The package itself requires zero Node runtime. The `codeguard-full` preset references `jscpd` (Node) because no PHP CPD tool currently matches its quality — we document the requirement instead of hiding it.

---

## Stack Requirements

- PHP **8.5+**
- Laravel **11** or **12**
- Pest **3** or **4** (dev only)
- Composer **2.x**

Optional:
- Node.js **18+** (only if using `codeguard-full` preset)
- CaptainHook: installed automatically as a Composer dev dependency (no separate binary required)

---

## Architecture

CodeGuard is a thin layer over best-in-class standalone tools, not a replacement:

```
Your Laravel project
     │
     ▼
codeguard:install      ◄── guided setup (this package)
     │
     ├─► pint.json              ──► laravel/pint (format)
     ├─► phpstan.neon           ──► phpstan/phpstan (type safety)
     ├─► deptrac.yaml           ──► qossmic/deptrac (architecture)
     ├─► infection.json5        ──► infection/infection (test quality)
     ├─► captainhook.json       ──► captainhook/captainhook (pre-commit, PHP-native)
     ├─► .jscpd.json            ──► jscpd (duplication, Full only)
     ├─► tests/Arch/*.php       ──► Pest architecture tests
     └─► config/codeguard.php   ──► Artisan orchestrator (codeguard:check, :test)
```

---

## Documentation

- [Architecture spec v5](docs/specs/2026-04-16-codeguard-v2-architecture.md) — canonical design, ADRs, roadmap
- [Pivot rationale (Node → PHP)](docs/specs/2026-04-16-pivot-npm-to-composer.md) — why the rewrite
- [Legacy npm package](docs/legacy/npm-v0-README.md) — `@henryavila/codeguard@0.1.1` reference

## Migrating from npm v0.x?

`0.2.0` is a **complete rewrite** from Node to PHP/Composer. No programmatic migration path.

- **If you use PHP/Laravel**: install fresh and re-configure via `php artisan codeguard:install`.
- **If you use Node**: continue with `@henryavila/codeguard@0.1.1` on npm (no further updates planned). The PHP rewrite continues semver from 0.x: PHP `0.2.0` is unrelated to npm `0.1.1` other than the legacy version pointer.

---

## Contributing

Currently in closed active development by [@henryavila](https://github.com/henryavila). Open issues welcome for questions and design feedback. PRs accepted now that `0.2.0` is tagged — but expect rapid breaking changes during the 0.x series.

## License

MIT © Henry Avila
