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

### R1/R2 — not yet exercised (closed 2026-07-27 — see below)

Both runs were single-pass (`samples: 1`, no `--critique`). Precision came from the
per-file subagents' own discipline (they omitted the documented FP cases without a
voting/critique pass).

**Model:** Opus 4.8 (Claude Code subagents) · **Date:** 2026-06-04 · **False positives observed:** `mass-assignment` in Filament (design limit, see above); otherwise the subagents self-suppressed the documented FP risks (bindings, eager-loaded relations, cursor/chunk, bounded lookups, documented typed catches).

---

## Last run — 2026-07-27 (Arch `app/Services`, R1 voting + R2 critique)

Second field run: CodeGuard (`dev-main` / vendor `111e82c`) against **Arch**
`app/Services` only. Emit + skill + ingest on a machine **without** LDAP/Mongo/SQL Server
(extensions ignored; no DB needed for analyze).

| Step | Command / note |
|---|---|
| Emit | `php artisan codeguard:analyze --emit --samples=3 --critique --path=app/Services` |
| Skill | `/codeguard-review` against existing `.codeguard/analyze-request.json` (must **not** re-emit with default `--changed-only` — clean tree yields 0 units) |
| Ingest | `php artisan codeguard:analyze --ingest=.codeguard/analyze-findings.json --fail-on=never --path=app/Services` |

| Metric | Value |
|---|---:|
| Units (files) | 110 |
| Voting passes | 3 |
| Critique | yes (`verified_score`) |
| Raw findings (all passes) | 243 (77 + 83 + 83) |
| Survivors after ≥2/3 vote (+ critique drop-0) | **79** |
| Checks reported by package | 2518 |
| Vote 3/3 | 44 |
| Vote 2/3 | 35 |
| Critical / warning / suggestion (from finding severity) | 21 / 55 / 3 |

### Survivors by pattern

| Pattern | Count |
|---|---:|
| type-declarations | 20 |
| unbounded-query | 8 |
| service-layer | 8 |
| eloquent-n-plus-one | 6 |
| dry | 6 |
| layer-dependency-direction | 6 |
| exception-handling | 5 |
| no-html-in-php | 3 |
| separation-of-concerns | 3 |
| missing-database-transaction | 3 |
| consistent-error-handling | 3 |
| small-functions | 3 |
| few-arguments | 2 |
| raw-sql-injection | 1 |
| no-constructor-many-params | 1 |
| single-responsibility | 1 |
| mass-assignment | **0** |
| missing-authorization | **0** (not selected on Services) |

### R4 re-check under voting + critique

| Pattern | Result |
|---|---|
| raw-sql-injection | ✅ still caught `ElectronicFillingService:109` (vote 2/3, critique 8/10) |
| missing-database-transaction | ✅ `ElectronicFillingService:35` (3/3, 8/10) + 2 more sites |
| eloquent-n-plus-one | ✅ `EmployeeSyncService:350` (3/3) + 5 more (incl. `:29` at 2/3) |
| unbounded-query | ✅ 8 survivors (EmailMarketing, GetinReport:585, Sync `::all()`, PrinterScraper…) |
| mass-assignment | ✅ still **0** on Services (matcher attaches, adjudicator correctly silent) |
| missing-authorization | — not selected for Services |

### R1 / R2 effect

- **R1 (voting):** raw 243 → 79 survivors (~67% cut). A finding needs ≥2 of 3 independent passes (`pattern_key` + `file` + `line`). Confidence on the report is vote-share (`1.00` = 3/3, `0.67` = 2/3).
- **R2 (critique):** findings show `[score N/10]`; package drops score **0**. This run kept low-but-nonzero scores (e.g. unbounded-query on lookup-ish tables scored **2–3/10** still survived — critique softens signal but only hard-drops zeros).
- **R3:** `layer-dependency-direction` still fires on `GetinReportService:7` (service → controllers) and several service→HTTP/Nova leaks (vote 2/3 after arch pass).

### Caveats observed this run

1. **Default skill scope trap:** clean git tree + `--changed-only` → empty work order and **overwrites** a good emit. Always pass `--path=…` / `--all` or reuse an existing request JSON.
2. **Noise load:** `type-declarations` (20) dominates survivors — useful but not G3-critical; consider lower severity or tighter scope for contractor-gate runs.
3. **Low critique scores still keep:** unbounded-query hits with score 2–3/10 remain after voting (only 0 is dropped). If that is too chatty, raise the critique floor in the package later — **not** changed this run.
4. **mass-assignment Filament limit** from 2026-06-04 still stands (0 on Services here; models-side issue remains open).

**Verdict:** R1+R2 work on a real module. Core G3 anchors (raw SQL, N+1, missing transaction, unbounded query, inverted layer deps) **survive voting**; volume is dominated by typing/style patterns. Field validation of Tier 2 mechanics is **done** for `app/Services`.

**Model:** Claude Code subagents (session 2026-07-27) · **Date:** 2026-07-27 · **Host:** macOS, PHP 8.5, no `ext-ldap`/`ext-mongodb`/`sqlsrv` (irrelevant to analyze).

### Product calibration (2026-07-27, post-audit)

Field audit showed R4/layer findings are genuine but ~25% of volume is low-value
hygiene (`type-declarations`, soft unbounded scores 2–3/10). Package now ships:

| Flag / config | Effect |
|---|---|
| `--focus=contractor` / `patterns.focus=contractor` | Only G3 keys (R4 + architecture + `service-layer`) |
| `--min-critique-score=N` (contractor default **4**) | Drop critiqued findings with score &lt; N; uncritiqued kept |
| `--only-patterns=a,b` | Explicit key allowlist |
| `--focus=full` (default product config) | **Excludes `classification: hygiene`** (types, dry, small-functions, few-arguments, no-constructor-many-params) |
| `--include-hygiene` | Restores hygiene inventory under full focus |
| `--base=origin/main` | PR-style file scope (base…HEAD ∪ staged ∪ unstaged) |
| Work order `scope.files` | Ingest reuses emit file list (parity); SHA drift needs `--allow-scope-drift` |
| CLI action sections | BLOCK / REQUEST CHANGE / INFO + markdown checklist |

Replayed on the same Arch ballot (vote ≥2/3, then filters): **79 full → ~28 contractor**
(removes type-declarations/dry/html noise; drops soft unbounded scores &lt; 4).

### Skill value uplift (2026-07-29) — signals + mass-assignment write-site

| Change | Detail |
|---|---|
| **R4 signals tightened** | Paths narrowed from `app/**` to Services/Http/Actions/Jobs/(Filament) as per plan D1 — Arch anchors under `app/Services/…` still match |
| **mass-assignment write-site only** | Controllers / Livewire / Filament / Nova only — **not** `app/Models/**`, **not** Services. Model-level `$guarded = []` selection deferred (needs content matcher) |
| **mass-assignment verification** | Prefer write-site findings; Filament form schema is the boundary; do not flag model `$fillable` alone |
| **Skill defaults** | contractor + critique; prefer `--base`; ingest uses `scope.files` |
| **Empty emit** | Package refuses to overwrite non-empty work order with empty (use `--force`) |

**Deferred:** content-body signal / `all_of` matcher; model `unguard()` selection tests.
