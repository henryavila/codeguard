---
name: codeguard-review
description: Run CodeGuard's pattern-based review — emit a work order, fan out batched subagents to judge each file against its patterns using your Claude subscription, then ingest + gate the validated findings. No external API, no metered tokens.
---

# /codeguard-review

| IDE | How to invoke |
|---|---|
| Claude Code | `/codeguard-review` |
| Cursor | Mention "codeguard-review" in chat |

## Overview

`codeguard:analyze` reviews code against the curated pattern corpus for smells AST
tools (PHPStan/Deptrac) cannot reach — "god object", "logic in blade",
"service-layer discipline". The LLM judgement runs **inside this Claude Code
session** (your subscription), so there is **no external/metered API**.

Division of labour:
- **The package (deterministic, in PHP)** scopes files, matches patterns, emits a
  work order, and later validates + gates the findings through its trust boundary.
- **You (this skill, via subagents)** do the actual reviewing — one subagent per
  batch of files — and hand the findings back.

The package's `PatternMatch` trust boundary re-validates everything you return, so
a hallucinated pattern key, a wrong file, or an out-of-range severity is dropped.

## Prerequisites

- The project depends on `henryavila/codeguard` (`composer show henryavila/codeguard`).
- It is a git repository (for the default `--changed-only` scope).

## Instructions

### Step 1 — Choose the scope

Default to changed + staged files. Honor the user's request if they name a path or
ask for a full scan:

| User intent | Scope flag |
|---|---|
| (default) review my current work | `--changed-only` |
| review `app/Services` | `--path=app/Services` |
| review a single file | `--path=app/Foo.php` |
| review the whole project | `--all` |

Use the SAME scope flag in Step 2 and Step 6 — the package re-derives the file set
on ingest, so they must match.

### Step 2 — Emit the work order

```bash
php artisan codeguard:analyze --emit --out=.codeguard/analyze-request.json <scope-flag>
```

For a higher-confidence review, request **voting** with `--samples=3` — run the
review 3 independent times and keep only what the passes agree on (Step 4). This
trades ~3× the subagent tokens for a finding's confidence becoming a *calibrated
vote-share* instead of the model's self-reported (and easily inflated) number:

```bash
php artisan codeguard:analyze --emit --samples=3 --out=.codeguard/analyze-request.json <scope-flag>
```

Emit calls no LLM. It writes JSON:

```json
{
  "system_prompt": "You are a senior code reviewer ...",
  "finding_schema": { "type": "array", "items": { "...": "..." } },
  "samples": 1,
  "units": [
    {
      "file": "/abs/path/app/Services/OrderService.php",
      "patterns": [
        {
          "key": "service-layer",
          "description": "Controllers delegate business logic to Services",
          "severity": "critical",
          "verification_rules": ["services must not return HTTP responses", "..."],
          "examples": { "correct": "...", "violation": "..." }
        }
      ]
    }
  ]
}
```

Read the file. If `units` is empty, tell the user nothing matched the scope and
stop. Note the `samples` value — it tells you how many review passes to run in
Step 4 (1 = single pass, the default).

### Step 3 — Batch the units

Group `units` into **batches of 5 files** (default). For a large diff, a bigger
batch trades parallelism for fewer subagent tokens; for a tiny diff, one batch is
fine. Never split a single unit across batches — a file and its patterns stay
together.

### Step 4 — Fan out one subagent per batch

For each batch, dispatch a subagent (Task/Agent tool, or Workflow `parallel` for
deterministic fan-out). Give each subagent:

- The `system_prompt` verbatim from the work order.
- Its batch of units (each unit's `file` + `patterns`).
- This instruction:

  > For each unit, READ the file at its `file` path. Judge it ONLY against that
  > unit's `patterns`, using each pattern's `verification_rules` and
  > `examples.correct`/`examples.violation` as the rubric. Report a finding ONLY
  > for a real violation you can point to a specific line for — do not invent
  > issues; when unsure, omit. Return a JSON array of findings, each:
  > `{ "pattern_key", "file", "line", "message", "severity", "confidence" }`,
  > where `pattern_key` is the exact key of the violated pattern, `file` is the
  > exact path you were given, `severity` is that pattern's severity, and
  > `confidence` is 0.0–1.0. Return `[]` when the batch is clean.

Subagents are independent — they cannot see each other's files. That isolation is
intended (one file's review never bleeds into another's).

**Voting (`samples` > 1):** run this whole batched fan-out `samples` times. Each
pass is a *fresh, independent* set of subagents over the same units — do NOT show
one pass the previous pass's findings (independence is what makes the vote
meaningful). Collect each pass's merged findings as a separate array; you will
hand all of them back in Step 5. The package keeps only findings that ≥2/3 of the
passes agree on, and sets each survivor's confidence to its vote-share.

### Step 4b — Architectural review (only when `architecture.patterns` is non-empty)

Three patterns judge *relationships between files* — dependency direction, module
boundaries, dependency cycles — which a per-file pass cannot see. The work order
carries them under `architecture.patterns`, plus a `graph` the package built from
the files' real `use` statements:

```json
"graph": {
  "nodes":  [ { "fqcn": "App\\Services\\OrderService", "file": "app/Services/OrderService.php" } ],
  "edges":  [ { "from": "App\\Services\\OrderService", "to": "App\\Repositories\\OrderRepository" } ],
  "cycles": [ [ "App\\Orders\\OrderService", "App\\Shipping\\ShippingService" ] ]
}
```

Dispatch **one** subagent for the whole graph (not per file). Give it the
`system_prompt`, the `graph`, and `architecture.patterns`, with this instruction:

  > Judge the dependency `graph` against these architectural patterns. `edges` are
  > real first-party `use` dependencies (`from` → `to`); `cycles` are dependency
  > cycles already detected for you — treat each as a likely
  > `no-circular-dependencies` violation and confirm it. READ a node's `file`
  > before reporting it. Return a finding ONLY for a real violation, each:
  > `{ "pattern_key", "file", "line", "message", "severity", "confidence",
  > "related_file" }`, where `file` is the offending node's path (exactly as in
  > the graph) and `related_file` is the FQCN at the other end of the bad
  > dependency. Return `[]` when the architecture is clean.

Add this subagent's findings to the same merged array as the per-file ones (and,
when voting, to each pass's array). The package attributes them to the cited file
through the same trust boundary — even a file that matched no per-file pattern.

### Step 5 — Merge findings

**Single pass (`samples: 1`).** Concatenate every subagent's findings into one
array and write it:

```bash
# write the merged array to .codeguard/analyze-findings.json
```

```json
{ "findings": [ { "pattern_key": "service-layer", "file": "/abs/.../OrderService.php", "line": 42, "message": "...", "severity": "critical", "confidence": 0.9 } ] }
```

A bare top-level array is also accepted.

**Voting (`samples` > 1).** Write one merged array *per pass* under a `samples`
envelope — the package does the voting, so keep the passes separate (do not merge
or dedupe them yourself):

```json
{
  "samples": [
    [ { "pattern_key": "service-layer", "file": "/abs/.../OrderService.php", "line": 42, "message": "...", "severity": "critical", "confidence": 0.9 } ],
    [ { "pattern_key": "service-layer", "file": "/abs/.../OrderService.php", "line": 42, "message": "...", "severity": "critical", "confidence": 0.8 } ],
    [ ]
  ]
}
```

The reported `confidence` inside each pass is ignored — the package overwrites it
with the calibrated vote-share (here 2/3 ≈ 0.67).

### Step 5b — Critique pass (only when the work order has `critique: true`)

A critique pass cuts false positives by making a *fresh* subagent re-judge each
finding instead of trusting the pass that produced it. For every finding you are
about to submit, dispatch a subagent that:

- READS the cited `file` around the cited `line`.
- Re-judges the finding against its pattern's rubric, with this instruction:

  > Score how real and on-target this finding is, 0–10. **0 means it is wrong,
  > a false positive, or not actually a violation of this pattern.** 10 means it
  > is a clear, correct violation at that line. Return only the integer.

Attach the integer to the finding as `verified_score`. A finding the critique
scored **0 is dropped by the package**; any positive score is kept and shown as
`[score N/10]`. Leave `verified_score` off a finding you did not critique.

```json
{ "pattern_key": "service-layer", "file": "/abs/.../OrderService.php", "line": 42, "message": "...", "severity": "critical", "confidence": 0.9, "verified_score": 8 }
```

If you are *also* voting (Step 4), critique each pass's findings before writing
that pass's array into the `samples` envelope — the package votes first, then
drops any survivor whose `verified_score` is 0.

### Step 6 — Ingest, validate, and gate

```bash
php artisan codeguard:analyze --ingest=.codeguard/analyze-findings.json --fail-on=critical <same-scope-flag>
```

The package re-scopes + re-matches, runs every finding through the trust boundary
(dropping anything that references an unknown pattern, the wrong file, or an
invalid severity/confidence), prints the surviving findings grouped by severity
(`✗` critical / `⚠` warning / `→` suggestion), emits `analyze.ended` telemetry, and
exits non-zero when any finding meets `--fail-on`.

### Step 7 — Report

Summarize for the user: how many files reviewed, how many findings survived
validation (and how many raw findings were dropped, if notable), and the exit
status. Offer to open the flagged files at the cited lines.

## Notes

- **Subscription, not API.** Nothing here calls a metered endpoint. The review is
  your Claude Code session doing the judging.
- **Not an autonomous CI gate.** This runs when invoked. For unattended CI, the
  AST gates (`composer codeguard:check` → Pint/PHPStan/Deptrac) remain the
  autonomous enforcement; `analyze` is the deeper assisted review.
- **`--fail-on`** accepts `critical` (default), `warning`, `suggestion`, or `never`
  (report-only).
- **`--samples=k`** (R1 voting) raises precision by agreement, not by trusting a
  single pass. A finding survives only if ≥2/3 of the `k` passes report it
  (`pattern_key` + `file` + `line`); its confidence becomes the vote-share. Use it
  when a false positive would be expensive (e.g. gating a contractor's PR); skip it
  (default `1`) for a quick local pass.
- **`--critique`** (R2) adds a second-opinion re-scoring pass (Step 5b): a fresh
  subagent re-judges each finding 0–10 and the package drops the 0s. Cheaper than
  voting (one extra pass, not `k`) and composes with it. Reach for it when you want
  a self-check without the cost of a full re-review.
- **Architectural patterns** (R3, Step 4b) reach cross-file smells — wrong
  dependency direction, module-boundary leaks, dependency cycles — using the
  namespace `graph` the package builds from real `use` edges. The graph is only as
  complete as the scope: under `--changed-only` it sees just the changed files, so
  run `--all` (or `--path` over a module) for a trustworthy architectural pass.
