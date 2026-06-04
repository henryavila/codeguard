# Patterns engine — recall log

Two halves to "does it catch what my contractor breaks?":

1. **Selection** (automated, deterministic, subscription-free) — does the matcher
   *attach* the right pattern to the right file? Covered by
   `tests/Unit/Analyze/PatternSelectionCoverageTest.php`.
2. **Recall** (manual) — does the subagent, given the right patterns, actually
   *catch* the smell? This cannot run in CI: judgement runs on the Claude Code
   subscription (no metered API, no `claude -p`). So it is measured by a human
   running `/codeguard-review` against known-bad fixtures and recording the result
   here. Refresh on demand (after corpus changes, prompt changes, or a model bump).

## How to refresh

1. Create/keep a small project with one known violation file per pattern under test.
2. Run `/codeguard-review` (or `php artisan codeguard:analyze --emit …` + subagents
   + `--ingest …`) over them.
3. Record caught / missed / false-positive below, with the model + date.

## Last run — 2026-06-04 (Arch field run, real codebase)

First real field run: CodeGuard (`dev-main`) against **Arch** (a large Filament/Laravel
app), via the `/codeguard-review` context-emit flow (Opus 4.8 subagents on the Claude
Code subscription — no metered API). Two modules, single-pass (`samples: 1`), `--fail-on=never`.

| Module | Files | Units | Raw findings | Survived trust boundary |
|---|---:|---:|---:|---:|
| `app/Http` (controllers) | 45 | 45 | 44 | 44 |
| `app/Services` | 98 | 98 | 46 | 46 |

Selection worked (matcher attached the right patterns); recall fired the right smells;
the trust boundary dropped **0** structural hallucinations — the subagents stayed within
their dispatched pattern keys (the F-001 hardening would have dropped any stray key).
`--all` on the whole app yields ~3,690 units (~93% smaller than before the vendor-scope
fix), so review is done **per module** to keep the subagent fan-out sane.

### R4 — high-impact corpus (the reason `analyze` exists) — RESULTS

Measured on `app/Services` (where these patterns actually live; controllers delegate).

| Pattern | Caught (real example) | False-positive risk — held up? |
|---|---|---|
| raw-sql-injection | ✅ `ElectronicFillingService:109` — `spw_code` interpolated into `DB::select` | ✅ did NOT flag `selectRaw`/raw built only from driver constants + `?` bindings |
| eloquent-n-plus-one | ✅ `EmployeeSyncService:293` — `Area::where()->first()` per row in a `foreach` (3 total) | ✅ did NOT flag relations already eager-loaded with `->with()` |
| missing-database-transaction | ✅ `ElectronicFillingService:35` — filling + files + raw update across writes, no `transaction()` (3 total) | ✅ did NOT flag single isolated writes, nor `DB::transaction()`+`lockForUpdate` |
| unbounded-query | ✅ `EmailMarketingModelService` / `GetinReportService:483` — `Model::all()`/`->get()` on growing tables (6 total) | ✅ did NOT flag `->cursor()` / `chunkById()` / fixed lookup tables |
| mass-assignment | ⚠️ N/A — 0 fired, **correctly** | ⚠️ **FP realized as a design limit — see below** |
| missing-authorization | — not exercised (not selected for Services; controllers/Filament policies gate elsewhere) | — |

**Verdict:** the four applicable R4 patterns fired with real, line-citable violations
AND held precision (every documented FP risk was correctly avoided). This is the core G3
signal working on real contractor-style code.

### ⚠️ Recall finding — `mass-assignment` is a false positive in Filament apps

The matcher attaches `mass-assignment` to **every** Eloquent model (391/391 in Arch). But
in a Filament app the security boundary is the **form schema**, not Eloquent's
`$fillable`/`$guarded`. In Arch: no global `Model::unguard()` (verified across `app/`,
`bootstrap/`, `config/`), 9 models `$guarded = []`, 166 with `$fillable`, 216 on Laravel's
guarded-by-default. So the pattern would flag intent that is either deliberate
(Filament-managed) or already safe. **Action item for the corpus:** make
`mass-assignment`'s `verification_rules` Filament-aware (suppress when the model is driven
by a Filament Resource / has form-layer field control), or gate the pattern off for
Filament projects. Recorded so it is not re-discovered.

### R3 architecture — FIRED

`layer-dependency-direction` caught a real inverted dependency: `GetinReportService`
imports and drives five `Http\Controllers\…\Getin` controllers (via an `INDICATORS` map +
`app($class)`), reversing the required Controller→Service direction (confidence 0.95). The
namespace-graph pass surfaced a cross-file smell a per-file review cannot see — its purpose.

### R1/R2 — not yet exercised

Both runs were single-pass (`samples: 1`, no `--critique`). Precision came from the
per-file subagents' own discipline (they omitted the documented FP cases without a
voting/critique pass). Next: re-run a module with `--samples=3 --critique` to measure
whether voting/critique changes the survivor set.

**Model:** Opus 4.8 (Claude Code subagents) · **Date:** 2026-06-04 · **False positives observed:** `mass-assignment` in Filament (design limit, see above); otherwise the subagents self-suppressed the documented FP risks (bindings, eager-loaded relations, cursor/chunk, bounded lookups, documented typed catches).
