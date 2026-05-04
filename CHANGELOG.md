# Changelog

All notable changes to `henryavila/codeguard` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Pre-1.0 means the public API may shift; pin to a specific minor (`^0.2`).

## [0.2.0] — 2026-05-04

First Packagist release. Complete rewrite from the legacy Node/TypeScript
package (`@henryavila/codeguard@0.1.1` on npm, no longer maintained) to a
Laravel-first PHP Composer package.

### Added

#### Commands

- `codeguard:install` — guided hybrid install with auto-detection of preset (`codeguard` or `codeguard-full`), inline-educational stub publishing, Deptrac layer suggestion from your `app/` namespaces, CaptainHook activation, Composer `allow-plugins` check, `phpunit.xml` strict-mode floor, and a minimal CI workflow stub.
- `codeguard:install --refresh-stubs` — diff-aware re-run; `keep`, `overwrite`, or review per file. No silent customisation loss.
- `codeguard:install --no-interactive` — CI mode, uses detection result without prompting.
- `codeguard:install:override` — register stub paths to keep across re-runs.
- `codeguard:check` — runs all enabled gates sequentially with fail-fast and a consolidated report. Layer 3 telemetry per-gate via `Gates\GateRunner`.
- `codeguard:test` — multi-stage test runner driven by `StageConfig` in `config/codeguard.php`. Sequential or parallel, fail-fast modes, Layer 5 telemetry. Project-agnostic (no Playwright/MongoDB/Nova assumptions).
- `codeguard:telemetry:enable` / `disable` / `clear` — opt-in JSONL telemetry under `.codeguard/telemetry/` with field allowlist, rotation, and privacy-safe enums.

#### Stubs (11 published in `codeguard-full`, 9 in `codeguard`)

- `pint.json.stub` — Laravel preset + 13 quality rules with inline `_rule_docs` explanations
- `phpstan.neon.stub` — level 5 default, includes Larastan + cognitive-complexity + dead-code + disallowed-calls extensions, sentinel markers for `PhpstanExtensionApplier`
- `phpstan-test-quality.neon.stub` — disallowed methods in `tests/`
- `deptrac.yaml.stub` — populated by `DeptracLayerWizard` from app namespace scan
- `infection.json5.stub` — Pest-compatible `phpunit` testFramework, `--no-progress`
- `captainhook.json.stub` + `captainhook.json.README.md.stub` — PHP-native git hook runner
- `phpunit.xml.stub` — `failOnRisky="true"` + `beStrictAboutTestsThatDoNotTestAnything="true"` enforce anti-pattern #4
- `.github/workflows/codeguard-ci.yml.stub` — minimal workflow that delegates everything to `composer codeguard:check`
- `.jscpd.json.stub` (full only) — duplication threshold 10%
- `tests/Arch/TestQualityTest.php.stub` (full only) — 7 Pest arch-tests using the bundled assertion traits

#### Assertions

- `TestQualityAssertions` Pest trait — `assertNoTautologicalAssertions`, `assertNoEloquentModelMocking`, `assertNoBareAssertNotNull`, `assertNoDbQueriesInFactoryDefinition`, `assertNoEagerCreateInFactoryDefinition`. All accept `allowlist: []` for documented escapes.
- `ParallelSafetyAssertions` Pest trait — `assertNoTruncateInTests`, `assertNoForceDeleteInTests`. Catches state-leak patterns that break parallel runners.

#### Telemetry (opt-in)

- `Telemetry\Recorder` — JSONL writer with field allowlist enforced via `FieldAllowlist`
- `Telemetry\StopwatchScope` — wall-clock timing primitive
- `Telemetry\MeasuredAction` — wraps CaptainHook actions with timing emission
- `Telemetry\Rotator` — daily file rotation under `.codeguard/telemetry/`
- `Install\InstallTelemetry` (Layer 1)
- `Gates\GateRunner` (Layer 3 — `gate.started`, `gate.ended`)
- `Commands\CodeguardTestCommand` (Layer 5 — `command.start`, `test.started`, `test.ended`, `command.end`)

#### Internal infrastructure

- 2 presets: `codeguard` (PHP-native default) and `codeguard-full` (+ jscpd + Insights + TestQualityTest, requires Node).
- Auto-detection via `package.json` and `node_modules/` presence.
- `StubRegistry` + `StubDefinition` for declarative stub publishing.
- `StubOverrides` — diff-aware overwrite protection per file.
- `GatePlan` + `GatePlanRegistry` for installer summary display.
- `Testing\TestSuiteRunner` — generalised from a 522-LOC Arch internal class to a 400-LOC ctor-injected runner via `StageConfig`. `CommandExecutor`, `RunningCommand`, `AsyncCommandExecutor` interfaces with `Process*` concrete implementations and `Fake*` test doubles.

### Changed

- Default PHPStan level: 5 (was unset). Stub ships at 5; consumers raise per project. Same strategy as Arch.
- Infection: `testFramework: phpunit` (was `pest`, rejected by Infection 0.32). `--no-progress` instead of invalid `--show-mutations=false`. PHPStan stub also adds `--memory-limit=2G --no-progress` for projects > 20k LOC.
- Pint: 1.29+ rejects `_rule_docs` inside `rules`. Moved to top-level meta keys.
- shipmonk/dead-code-detector: removed obsolete `usageProviders.laravel|eloquent` (gone in 0.14+).

### Deprecated

— (none)

### Removed

- Node.js runtime dependency from the package itself. The legacy npm package (`@henryavila/codeguard@0.1.1`) is preserved on npm but no longer maintained. The `codeguard-full` preset still references `jscpd` (Node) because no PHP CPD tool currently matches its quality — this is documented as an explicit Node opt-in, not hidden.
- Lefthook git hook runner. Replaced by CaptainHook (ADR-010, 2026-04-20) which is PHP-native and Composer-distributed — no separate binary install needed.

### Fixed

— (first release)

### Quality gates

- 377 tests / 928 assertions (Pest 3.x and 4.x compatible)
- PHPStan level 5 self-applied with grandfathered baseline (405 errors in baseline; new code must pass)
- Pint clean
- Package CI runs on PHP 8.3 + 8.4

[0.2.0]: https://github.com/henryavila/codeguard/releases/tag/0.2.0
