# CodeGuard

> Laravel quality gates that survive your AI agent.

**Status**: v1.0 in early development (post-pivot from Node.js to PHP/Composer)

CodeGuard is a Composer package providing unified quality gates for Laravel projects — test orchestration, pattern analysis, schema dump acceleration, and AI rules generation. Optional Claude plugin adds AI-time enforcement hooks.

## Current Status (2026-04-16)

This repository recently pivoted from a Node.js/TypeScript npm package to a PHP/Composer package. See [pivot spec](docs/specs/2026-04-16-pivot-npm-to-composer.md) for rationale.

- **Legacy Node version**: `@henryavila/codeguard@0.1.1` remains on npm (branch `v0-npm-archive`, tag `v0-last-npm`)
- **New PHP version**: under active development on `main` branch
- **Not yet on Packagist** — coming in v1.0.0-alpha.1

## Planned Features (v1.0)

### Core (`henryavila/codeguard` Composer package)
- `codeguard:install` — zero-config wizard (Pint + PHPStan default)
- `codeguard:check` — unified quality gates orchestration (auto-detect tools)
- `codeguard:test` — multi-stage test runner (Vitest + Pest + MongoDB + Browser)
- `codeguard:prepare` — schema dump with hash caching (multi-DB: MySQL, PostgreSQL, SQLite `:memory:`, SQL Server fallback)
- `codeguard:analyze` — pattern engine over code diff (28 curated patterns: core + PHP + Laravel)
- `codeguard:baseline` — baseline management for incremental adoption

### Testing Kit
- `TestQualityAssertions` trait — detect tautological assertions, model mocking, bare assertNotNull
- `ParallelSafetyAssertions` trait — detect truncate, forceDelete, DB queries in factories
- Pest custom expectations: `expect()->quality()->noTautologicalAssertions()->noEloquentModelMocking()`

### AI Integration
- Multi-tool AI rules generator (Claude, Cursor, Copilot, Windsurf, AGENTS.md)
- Path-triggered rules (per-scope: services, models, controllers, tests)
- Claude skills embedded (`codeguard-setup`, `codeguard-run`, `codeguard-health`)
- Optional Claude plugin for AI-time config protection (best-effort nudges)

### Stubs
- PHPStan + Larastan config
- Pint config
- Deptrac template (opt-in)
- Husky / Lefthook pre-commit hooks
- GitHub Actions CI workflows

## Roadmap

- [ ] v1.0.0-alpha.1 — MVP extracted from [Arch](https://github.com/henryavila/arch) laboratory (~2 weeks)
- [ ] v1.0.0-beta — validated in 2+ projects
- [ ] v1.0.0 — stable on Packagist
- [ ] v1.1 — pattern engine + baseline
- [ ] v1.2 — Claude plugin `henryavila/codeguard-hooks`
- [ ] v2.0 — companion packages (`codeguard-symfony`, `codeguard-filament`)

## Philosophy

> Core is YAML contracts; implementations are native to each language.

CodeGuard explicitly avoids the "language-agnostic core" trap (MegaLinter owns that space). Instead, each language ecosystem gets a native package sharing pattern definitions as data contracts.

**Target persona**: Laravel developers with ≥3 quality configs, AI-assisted codebase, multi-DB stacks, and one-or-more contracted developers on PRs.

**Not for**: Single-file APIs that just need `laravel/pint`. Use Pint directly.

## Documentation

- [Architecture Design (v4)](docs/specs/2026-04-16-codeguard-v2-architecture.md) — full design with 10 agent reviews
- [Pivot Spec (Node → PHP)](docs/specs/2026-04-16-pivot-npm-to-composer.md) — why the pivot
- [Legacy npm README](docs/legacy/npm-v0-README.md) — preserved for reference

## License

MIT © Henry Avila
