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
tools (PHPStan/Deptrac) cannot reach — SQL injection write sites, missing auth,
N+1, layer leaks. The LLM judgement runs **inside this Claude Code session**
(your subscription), so there is **no external/metered API**.

Division of labour:
- **The package (deterministic, in PHP)** scopes files, matches patterns, emits a
  work order (with a `scope` object), and later validates + gates findings through
  its trust boundary — including anti-overwrite on empty emit and action taxonomy
  (BLOCK / REQUEST CHANGE / INFO).
- **You (this skill, via subagents)** do the actual reviewing — one subagent per
  batch of files — and hand the findings back.

Default product posture: **contractor PR review** of the **relevant diff**, not a
full-corpus inventory of a clean tree.

## Prerequisites

- The project depends on `henryavila/codeguard` (`composer show henryavila/codeguard`).
- It is a git repository (for `--base` / default changed-only scope).

## Instructions

### Step 1 — Choose the scope

**Resolution order (honor the first that applies):**

1. User named `--path=…` or asked for a full scan (`--all`) → honor that.
2. User/skill can set `--base=…` (PR base, e.g. `origin/main`) → package uses
   `againstBase` = `base...HEAD` ∪ staged ∪ unstaged (unless `--committed-only`).
3. Detectable default branch → prefer `--base=origin/<default>` (e.g. `origin/main`).
4. Fallback: `--changed-only` (git dirty + staged vs HEAD).
5. If 0 files/units: **do not** treat “Working tree clean” as a successful review.
   Offer `--path`, a base branch, `--all`, or `--include-hygiene` as appropriate.

| User intent | Scope flag |
|---|---|
| (default) review this PR / current branch | `--base=origin/main` (or detected default) |
| dirty local work only | `--changed-only` |
| review `app/Services` | `--path=app/Services` |
| review a single file | `--path=app/Foo.php` |
| inventory whole project | `--all` (+ optional `--include-hygiene`) |
| CI / committed PR SHA only | `--base=origin/main --committed-only` |

**Ingest must not re-guess scope.** The work order carries `scope.files` (+ SHAs).
Always pass the **same** `--out` request path; ingest reuses `scope.files` from
`.codeguard/analyze-request.json` (or `--request=`). Do **not** re-pass divergent
scope flags that would re-derive git scope.

### Step 2 — Emit the work order

**Defaults when the user does not specify focus:**

- `--focus=contractor` — G3 security/data R4 + architecture + `service-layer`
- `--critique` — re-score pass; package drops soft scores below contractor floor (4)
- `samples=1` by default; use `--samples=3` only when the user asks for a hard gate / voting

```bash
php artisan codeguard:analyze --emit --focus=contractor --critique \
  --out=.codeguard/analyze-request.json <scope-flag>
```

**Hard gate / voting** (opt-in):

```bash
php artisan codeguard:analyze --emit --focus=contractor --critique --samples=3 \
  --out=.codeguard/analyze-request.json <scope-flag>
```

**Full inventory** (explicit):

```bash
php artisan codeguard:analyze --emit --focus=full --include-hygiene \
  --out=.codeguard/analyze-request.json --all
```

Note: `--focus=full` **excludes hygiene** (types, dry, small-functions, …) unless
`--include-hygiene` is set. Contractor already excludes hygiene via its key allowlist.

Emit calls no LLM. It writes JSON including:

```json
{
  "focus": "contractor",
  "min_critique_score": 4,
  "scope": {
    "mode": "base",
    "base_ref": "origin/main",
    "committed_only": false,
    "head_sha": "abc…",
    "merge_base_sha": "def…",
    "files": ["/abs/app/Services/Foo.php"]
  },
  "samples": 1,
  "critique": true,
  "units": [ { "file": "…", "patterns": [ … ] } ]
}
```

**Empty emit protection (package):** if `--out` already has `units.length > 0` and
the new work order has 0 units, the package **aborts** without writing (unless
`--force`). The skill does not reimplement that check — report the package error
and offer scope fixes (`--path`, `--base`, `--all`) or `--force` only when intentional.

If `units` is empty and write succeeded, tell the user nothing matched and stop.

Contractor + tightened R4 path signals → smaller units → cheaper subagent batches.

### Step 3 — Batch the units

Group `units` into **batches of 5 files** (default). Never split a single unit
across batches — a file and its patterns stay together.

### Step 4 — Fan out one subagent per batch

For each batch, dispatch a subagent. Give each:

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

**Voting (`samples` > 1):** run this whole batched fan-out `samples` times with
fresh independent subagents. Collect each pass's merged findings separately.

### Step 4b — Architectural review (only when `architecture.patterns` is non-empty)

Dispatch **one** subagent for the whole graph with `graph` + `architecture.patterns`.
Findings may include `related_file`. Add them to the merged array (per pass when voting).

### Step 5 — Merge findings

**Single pass:** write `.codeguard/analyze-findings.json`:

```json
{ "findings": [ { "pattern_key": "…", "file": "…", "line": 42, "message": "…", "severity": "critical", "confidence": 0.9 } ] }
```

**Voting:** write a `samples` envelope with one array per pass (do not merge yourself).

### Step 5b — Critique pass (when `critique: true`)

For every finding, a fresh subagent scores 0–10 into `verified_score`. Package drops
scores below `min_critique_score` (contractor default **4**); uncritiqued findings stay.

### Step 6 — Ingest, validate, and gate

**Reuse the same work order scope** — prefer request JSON, do not re-derive git:

```bash
php artisan codeguard:analyze \
  --ingest=.codeguard/analyze-findings.json \
  --request=.codeguard/analyze-request.json \
  --fail-on=critical
```

The package:
- Uses `scope.files` from the request when present (still-exists filter only).
- Warns / fails if `HEAD` ≠ `scope.head_sha` unless `--allow-scope-drift`.
- Prefers work-order `focus` / `min_critique_score` when CLI did not set them.
- Prints findings by **action section** + a markdown checklist (not only severity).

CLI focus/min-critique flags override only when the user passes them explicitly.

### Step 7 — Report (PR decision, not a flat inventory)

After ingest, use the **package sections** (do not reimplement action policy):

1. Summarize **block / request_change / info** counts from the CLI footer.
2. Paste the **Checklist (markdown)** block from the package output.
3. Offer to open the first **BLOCK** finding at its path:line.
4. For re-review after fixes: re-run with the **same work order scope** (same
   `--request` / paths / `--base`) — do not invent a new empty emit over a good one.
5. **Do not** propose bulk auto-fix.

Decision framing:
- **BLOCK** — do not merge until fixed (critical security: SQL injection, missing
  auth, mass-assignment at write site).
- **REQUEST CHANGE** — should be addressed (N+1, transactions, unbounded queries,
  layer/service leaks).
- **INFO** — hygiene/other survivors.

## Notes

- **Subscription, not API.** Nothing here calls a metered endpoint.
- **Not an autonomous CI gate.** AST gates remain autonomous; analyze is assisted review.
- **`--fail-on`** — `critical` (default), `warning`, `suggestion`, or `never`.
- **`--samples=k`** — R1 voting; opt-in for hard contractor gates.
- **`--critique`** — R2 re-scoring; default for skill contractor runs.
- **`--force`** — only when intentionally replacing a non-empty work order with empty.
- **`--allow-scope-drift`** — only when HEAD moved and you still want the recorded file list.
- **Architectural patterns (R3)** need a wide enough scope (`--all` / module path) for a trustworthy graph.
- **Trust boundary & voting stay in the package** — this skill never reimplements them.
