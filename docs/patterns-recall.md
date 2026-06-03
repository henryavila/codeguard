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

## Last run

> Not yet measured. Fill in after the first real `/codeguard-review` field run.

| Pattern | Known-bad fixture | Caught? | Notes |
|---|---|:---:|---|
| no-god-object | _tbd_ | _tbd_ | |
| service-layer | _tbd_ | _tbd_ | |
| no-logic-in-blade | _tbd_ | _tbd_ | |
| … | | | |

### R4 — high-impact contractor-dev corpus (priority — center of goal G3)

These are AST-invisible and the reason `analyze` exists; validate them first.

| Pattern | Known-bad fixture | Caught? | False positive risk to watch |
|---|---|:---:|---|
| mass-assignment | `Model::create($request->all())` | _tbd_ | DTO/`validated()` calls that aren't actually `->all()` |
| raw-sql-injection | `whereRaw("x = '{$input}'")` | _tbd_ | raw fragments built only from constants (must NOT flag) |
| missing-authorization | `update()` with no `authorize()` | _tbd_ | a FormRequest `authorize()` that already gates (must NOT flag) |
| eloquent-n-plus-one | relation access inside `foreach` | _tbd_ | relations already eager-loaded with `with()` then read in a loop |
| missing-database-transaction | parent+children writes, no `transaction()` | _tbd_ | a single isolated write (must NOT flag) |
| unbounded-query | `Model::all()` on a growing table | _tbd_ | small fixed lookup tables / already-`limit()`ed queries |

### R1/R2/R3 — precision levers to exercise during the field run

- **R1 voting** (`--samples=3`): does keeping ≥2/3 agreement drop the flaky findings? Record raw-vs-survived counts.
- **R2 critique** (`--critique`): does the re-score pass kill obvious false positives (score 0)?
- **R3 architecture** (`--all`): do `layer-dependency-direction` / `bounded-contexts` / `no-circular-dependencies` fire off the namespace graph, and are the detected `cycles` real?

**Model:** _tbd_ · **Date:** _tbd_ · **False positives observed:** _tbd_
