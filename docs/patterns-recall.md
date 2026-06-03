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

**Model:** _tbd_ · **Date:** _tbd_ · **False positives observed:** _tbd_
