# CodeGuard

> **Laravel quality gates that survive your AI agent.**

Consolidated install for Pint, PHPStan, Deptrac, Infection, and Lefthook — with AI review where AST can't reach, multi-database schema dump, and honest best-effort Claude hooks.

**Status**: v1.0 in active development. Installs and runs on Laravel 11/12 via `composer path repository` today. First Packagist tag (`1.0.0-alpha.1`) expected mid-2026-04.

---

## Why CodeGuard

You have a Laravel project. You want:
- **PHPStan** catching type bugs
- **Deptrac** enforcing architecture boundaries
- **Infection** detecting `assertTrue(true)` type tests written by a contractor who doesn't use AI
- **Lefthook** blocking bad commits before they hit CI
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

The installer auto-detects your environment, recommends a preset, shows you exactly what will be installed (with honest config-time estimates), asks Deptrac layer questions scanned from your `app/` directory, and sets up Lefthook hooks.

```
CodeGuard — Laravel quality gates installer

Detecting environment...
  PHP                   8.3.12
  Composer              2.7.0
  Node.js               20.10.0
  package.json          found
  Lefthook binary       available

Recommended preset ... codeguard-full ⭐
  • Project uses Node.js (package.json or node_modules detected).

=== Gates to install ===
  ✓ Pint             auto-format          config: 0         CI: ~5s
  ✓ PHPStan          type safety          config: 15min     CI: ~30s
  ✓ Deptrac          architecture         config: 30min     CI: ~15s
  ✓ Infection        test quality         config: 20min     CI: +3min
  ✓ Lefthook         pre-commit enforce   config: 10min     CI: 0
  ✓ jscpd            duplication          config: 5min      CI: ~10s
  ✓ Insights         metrics              config: 0         CI: ~20s
  ✓ TestQualityTest  meta-quality         config: 15min     CI: ~5s

  Estimated total config ......... 1h 35min

Proceed with install? [yes]

Publishing stubs...
  pint.json                                 created
  phpstan.neon                              created
  deptrac.yaml                              created
  infection.json5                           created
  lefthook.yml                              created
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

Lefthook setup
  lefthook install      installed (.git/hooks registered)

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
| **`codeguard`** (default) | Pint + PHPStan + Deptrac + Infection + Lefthook | ❌ | No `package.json` or `node_modules/` |
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

### ✅ Available today (v1.0.0-alpha WIP)

- **Guided hybrid install** — smart stubs with inline educational comments, auto-detection, Deptrac layer suggestion from your `app/` namespaces, Lefthook binary check and install
- **Idempotent re-run** — `--refresh-stubs` diffs existing files and lets you `keep`, `overwrite`, or review full diff before choosing. No silent customization loss.
- **Auto-detect Node** — installer picks the right preset for your project without asking
- **Honest estimates** — per-gate config time and CI cost shown before you commit

### 🚧 Coming in v1.0 stable

- `codeguard:check` — run all enabled gates sequentially with fail-fast and consolidated report
- `codeguard:test` — multi-stage test runner (Vitest + Pest + Browser + MongoDB stages heterogeneous)
- `codeguard:prepare` — multi-database schema dump (MySQL, PostgreSQL, SQLite `:memory:`, SQL Server PDO fallback, Windows without `sqlite3` CLI)
- `TestQualityAssertions` + `ParallelSafetyAssertions` traits + Pest custom expectations
- `codeguard:analyze` — pattern engine (28 curated YAMLs) with LLM adjudicator for where AST can't reach

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

- PHP **8.3+**
- Laravel **11** or **12**
- Pest **3** or **4** (dev only)
- Composer **2.x**

Optional:
- Node.js **18+** (only if using `codeguard-full` preset)
- Lefthook binary (installer suggests install commands if missing)

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
     ├─► lefthook.yml           ──► lefthook binary (pre-commit)
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

v1.0 is a **complete rewrite** from Node to PHP/Composer. No programmatic migration path.

- **If you use PHP/Laravel**: install v1.0 fresh and re-configure via `php artisan codeguard:install`.
- **If you use Node**: continue with `@henryavila/codeguard@0.1.1` on npm (no further updates planned).

---

## Contributing

Currently in closed active development by [@henryavila](https://github.com/henryavila). Open issues welcome for questions and design feedback. PRs accepted once v1.0.0-alpha.1 is tagged.

## License

MIT © Henry Avila
