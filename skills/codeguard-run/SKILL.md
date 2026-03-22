---
name: codeguard-run
description: Run static analysis and AI pattern analysis against project standards
---

# /codeguard-run

You are executing the `/codeguard-run` skill. This runs static analysis tools and AI semantic analysis against the project's configured patterns and standards. Follow every step precisely.

**Golden Rule: NEVER infer or fabricate tool output.** When tools fail or produce errors, report the exact output verbatim. Do not guess causes, diagnose issues, or add interpretations. Relay what the tool said, nothing more.

## IDE Invocation

| IDE | Syntax |
|---|---|
| Claude Code | `/codeguard-run` |
| OpenCode | `/codeguard-run` |
| Cursor | Mention "codeguard-run" in chat |
| Codex CLI | To be validated |
| Gemini CLI | To be validated |
| Copilot CLI | To be validated |
| Windsurf | To be validated |

---

## Step 1: Load Configuration

Read these files from the project root:

1. **`codeguard.yaml`** — project configuration (capabilities, patterns, thresholds, baseline path)
2. **`CODEGUARD.md`** — project-specific architecture context written during setup

If `codeguard.yaml` does not exist, stop and tell the user:
> codeguard.yaml not found. Run `/codeguard-setup` first to configure your project.

Extract from `codeguard.yaml`:
- `project.module` — the active module (e.g., `php-laravel`)
- `project.language` — the language (e.g., `php`)
- `capabilities` — which tools are enabled and their enforcement levels
- `patterns.catalog` — framework-specific pattern names selected during setup
- `patterns.discovered` — discovered patterns stored in `.codeguard/patterns/`
- `patterns.custom` — custom patterns stored in `.codeguard/patterns/`
- `thresholds` — limits like `max_method_lines`, `max_indentation_levels`
- `baseline.path` — path to baseline file (default: `.codeguard/baseline.json`)

## Step 2: Load AI Rules

Load the `ai-rules/*.md` files from all applicable module layers. The module hierarchy is resolved from `project.language` and `project.module` in `codeguard.yaml`. Read these files from `.codeguard/modules/` (or fall back to `node_modules/@henryavila/codeguard/modules/`):

1. **`.codeguard/modules/core/ai-rules/core.md`** — universal analysis rules (always loaded)
2. **`.codeguard/modules/{language}/ai-rules/{language}.md`** — language-specific (e.g., `.codeguard/modules/php/ai-rules/php.md`)
3. **`.codeguard/modules/{module}/ai-rules/*.md`** — framework-specific (e.g., `.codeguard/modules/php-laravel/ai-rules/laravel.md`)

Read all three and internalize the instructions. These govern how you analyze code: priority order, false positive prevention, severity classification, and detection heuristics.

## Step 3: Load Module Data

Read the module preset for tool binary paths and configurations:

- **`.codeguard/modules/{module}/preset.yaml`** (e.g., `.codeguard/modules/php-laravel/preset.yaml`)

This gives you:
- `tools.larastan.binary` — path to PHPStan binary (e.g., `vendor/bin/phpstan`)
- `tools.larastan.config` — config file name (e.g., `phpstan.neon`)
- `tools.larastan.level` — default analysis level
- `tools.pint.binary` — path to Pint binary
- `tools.phpmd.binary` — path to PHPMD binary
- `tools.phpmd.rulesets` — default rulesets
- `tools.pest.binary` — path to Pest binary
- `tools.pest.directory` — arch test directory (e.g., `tests/Architecture`)

When `codeguard.yaml` specifies overrides (e.g., `capabilities.static-analysis.level: 9`), use the override instead of the preset default.

## Step 4: Determine Scope

Ask the user what scope to analyze, or accept it from their initial message. Valid scopes:

| Scope | Meaning | Example user input |
|---|---|---|
| **Full project** | All PHP files in the project | "run on everything", "full project", "analyze all" |
| **Directory** | All PHP files in a specific directory | "analyze app/Services", "check the controllers" |
| **File** | A single file | "check app/Services/OrderService.php" |
| **Staged changes** | Files in `git diff --cached --name-only` | "check staged", "what I'm about to commit" |

For **staged changes**, run:
```bash
git diff --cached --name-only --diff-filter=ACMR
```
Filter the result to only `.php` files (or the relevant extension for the project language).

If the user does not specify a scope, default to **full project**.

Store the list of files in scope for use in subsequent steps.

---

## Step 5: Preflight — Check Tool Availability

Before running any tool, verify that all enabled tools are installed. For each enabled capability in `codeguard.yaml`, check if the binary exists:

```bash
test -f vendor/bin/phpstan && echo "OK" || echo "MISSING"   # static-analysis
test -f vendor/bin/phpmd && echo "OK" || echo "MISSING"     # mess-detection
test -f vendor/bin/pest && echo "OK" || echo "MISSING"      # arch-testing
test -f vendor/bin/pint && echo "OK" || echo "MISSING"      # formatting
```

Use the binary paths from `preset.yaml` (loaded in Step 3).

If **any** enabled tool is missing:

1. List the missing tools and their install commands (from `preset.yaml` `install_commands`)
2. Ask the user: "Install missing tools now? [Y/n]"
3. If yes, run each install command (e.g., `composer require --dev phpmd/phpmd`)
4. If install fails, disable the capability for this run and warn the user
5. If user says no, disable the capability for this run and note it in the report

**Do NOT skip this step.** A tool reported as "not installed" in the final report is a failure of this preflight check.

---

## Step 6: Run Static Analysis Tools

Run each enabled capability's tool against the scope. Check `codeguard.yaml` capabilities — only run tools where `enabled: true` and that passed the preflight check in Step 5.

### 6a. Larastan (static-analysis)

Larastan always runs on the **full project** regardless of scope (PHPStan needs full context for type inference). Run:

```bash
vendor/bin/phpstan analyse --error-format=json --no-progress --level={level}
```

Add `--configuration={config}` only if the config file exists in the project root (e.g., `phpstan.neon`). If it does not exist, omit the flag — PHPStan auto-discovers `phpstan.neon` or `phpstan.neon.dist`.

Where:
- `{level}` = `capabilities.static-analysis.level` from codeguard.yaml (falls back to preset default)
- `{config}` = `tools.larastan.config` from preset.yaml (e.g., `phpstan.neon`) — only if the file exists

After getting results, **filter output to scope** — only keep findings in files that are within the analysis scope determined in Step 4. PHPStan exit code 1 means there are findings (this is normal, not an error). Only treat exit codes >= 2 as tool errors.

Parse the JSON output. PHPStan JSON format:
```json
{
  "totals": { "errors": 0, "file_errors": 5 },
  "files": {
    "app/Services/OrderService.php": {
      "errors": 2,
      "messages": [
        { "message": "Call to undefined method ...", "line": 45, "ignorable": true }
      ]
    }
  }
}
```

### 6b. PHPMD (mess-detection)

PHPMD runs on **scoped files only**. Build a comma-separated file list from the scope:

```bash
vendor/bin/phpmd {file1},{file2},{file3} json {rulesets}
```

Where:
- `{rulesets}` = comma-separated list from `tools.phpmd.rulesets` in preset.yaml (e.g., `unusedcode,codesize`)
- If a project-level `phpmd.xml` exists, use it instead: `vendor/bin/phpmd {files} json phpmd.xml`

Parse the JSON output. PHPMD JSON format:
```json
{
  "version": "...",
  "package": "phpmd",
  "violations": [
    {
      "rule": "CyclomaticComplexity",
      "description": "The method ... has a cyclomatic complexity of 15.",
      "file": "app/Http/Controllers/OrderController.php",
      "beginLine": 23,
      "endLine": 80,
      "priority": 1
    }
  ]
}
```

### 6c. Pest Arch Tests (arch-testing)

Pest runs the **architecture test directory** (not scoped files):

```bash
vendor/bin/pest tests/Architecture/ --colors=never
```

Parse the text output. Look for FAIL lines:
```
FAIL  Tests\Architecture\CodeGuardArchTest > ...
```

Each failed arch test produces one finding.

### Tool errors

If a tool binary is not found, this should have been caught by the preflight check (Step 5). If it still happens:
- Report: "{Tool} not found at {binary_path}. Run `{install_command}` to install."
- Continue with remaining tools.

If a tool command fails (non-zero exit):
- **Report the actual stderr/stdout output verbatim.** Include the literal error text the tool produced.
- **NEVER diagnose or interpret the error.** Do not guess the cause (e.g., do not say "missing APP_KEY" or "environment error" unless those exact words appear in the tool's output). Your job is to relay the error, not to play detective.
- Format: "{Tool} failed (exit code {N}). Output: {literal output}"
- Continue with remaining tools.

---

## Step 7: Parse Tool Output

Normalize all tool findings into a unified structure:

For each finding, record:
- **tool** — which tool produced it (e.g., `larastan`, `phpmd`, `pest`)
- **rule** — the rule identifier (e.g., `method.notFound`, `CyclomaticComplexity`, arch test name)
- **file** — relative file path from project root
- **line** — line number (if available)
- **message** — the tool's message
- **enforcement** — from `codeguard.yaml` capability config (`block`, `warn`, or `autofix`)

---

## Step 8: Load Active Patterns

Load pattern YAML files from all layers in the module hierarchy:

1. **Core patterns** — all `.yaml` files in `.codeguard/modules/core/patterns/`
2. **Language patterns** — all `.yaml` files in `.codeguard/modules/{language}/patterns/` (e.g., `php`)
3. **Framework patterns** — only patterns listed in `codeguard.yaml` `patterns.catalog` from `.codeguard/modules/{module}/patterns/`
4. **Discovered patterns** — patterns listed in `patterns.discovered` from `.codeguard/patterns/`
5. **Custom patterns** — patterns listed in `patterns.custom` from `.codeguard/patterns/`

Core and language patterns are always active (they represent fundamental quality standards). Framework, discovered, and custom patterns are controlled by `codeguard.yaml`.

For each pattern YAML, extract:
- `name` — pattern identifier
- `description` — what the pattern enforces
- `severity` — `critical`, `warning`, or `suggestion`
- `verification.rules` — list of plain-English rules to check
- `examples.correct` — correct code example
- `examples.violation` — violation code example

---

## Step 9: AI Semantic Analysis

This is the **core value** of CodeGuard. You analyze the code in scope against every active pattern's verification rules, guided by the ai-rules loaded in Step 2.

### How to analyze

For each active pattern:
1. Read its `verification.rules` list
2. For each rule, scan the code in scope looking for violations
3. Apply the detection heuristics from the ai-rules (e.g., laravel.md describes exactly what to look for when checking "controllers must not access Eloquent models directly")
4. Apply false positive prevention rules from the ai-rules (e.g., route model binding is NOT a violation)
5. Consider the project context from CODEGUARD.md

### What to produce for each AI finding

- **pattern** — which pattern was violated (name + description)
- **rule** — which specific verification rule was broken
- **file** — the file path
- **line** — the line number or range
- **violation** — what was found (be specific: quote the offending code)
- **severity** — from the pattern's `severity` field, adjusted per ai-rules severity classification:
  - **Critical**: Core architecture broken, structural integrity undermined
  - **Warning**: Pattern partially followed, significant deviation
  - **Suggestion**: Improvement opportunity, code works but could be cleaner
- **remediation** — how to fix it (specific, actionable, with code example when helpful)

### Thresholds

Check thresholds from `codeguard.yaml`:
- `max_method_lines` — flag methods exceeding this line count (Warning severity)
- `max_indentation_levels` — flag nesting exceeding this depth (Warning severity)

These are AI-only checks. No deterministic tool enforces them.

---

## Step 10: Classify All Findings

Merge tool findings (Step 7) and AI findings (Step 9) into a single list. Classify each:

| Source | Symbol | Color | Meaning |
|---|---|---|---|
| Tool finding with `enforcement: block` | `✗` | Red | Blocking violation |
| Tool finding with `enforcement: warn` | `⚠` | Yellow | Warning, non-blocking |
| AI finding with severity `critical` | `✗` | Red | Critical pattern violation |
| AI finding with severity `warning` | `⚠` | Yellow | Pattern deviation |
| AI finding with severity `suggestion` | `→` | Blue | Improvement opportunity |

---

## Step 11: Generate Report

Present findings grouped by pattern/tool, ordered by severity (critical first, then warning, then suggestion).

### Report format

```
codeguard · analysis report
Scope: {scope description}

━━━ TOOL FINDINGS ━━━

  ✗ app/Services/OrderService.php:45
    Larastan: Call to undefined method calculateTotal()

  ⚠ app/Http/Controllers/OrderController.php:23
    PHPMD: CyclomaticComplexity — method has complexity of 15

━━━ PATTERN ANALYSIS ━━━

  service-layer — Controllers delegate business logic to Services

  ✗ app/Http/Controllers/OrderController.php:31
    Rule: controllers must not access Eloquent models directly
    Found: Order::create($request->all()) called directly in controller
    Fix: Move to OrderService. Inject OrderService and call $this->orderService->create(OrderData::from($request))

  ⚠ app/Http/Controllers/UserController.php:18
    Rule: controllers must not contain business logic
    Found: Complex discount calculation (if/else chain, lines 18-42)
    Fix: Extract to CalculateDiscountAction or UserService::calculateDiscount()

  dto — Use typed DTOs between layers

  → app/Services/PaymentService.php:55
    Rule: DTOs required between layers
    Found: Raw array returned from processPayment() to controller
    Fix: Create PaymentResult DTO with status, transactionId, amount fields

━━━ SUMMARY ━━━

  {total} findings · {critical_count} critical · {warning_count} warnings · {suggestion_count} suggestions
  Tool findings: {tool_count} ({baselined_count} baselined, suppressed)
  AI findings: {ai_count} (not baselined)
```

If there are no findings at all, report:
```
codeguard · analysis report
Scope: {scope description}

  All clear. No violations found.
```

---

## Step 12: Offer Corrections

After presenting the report, offer to fix violations:

1. **"Fix this"** — fix a specific finding (user points to one)
2. **"Fix all X violations"** — fix all findings for a specific pattern (e.g., "fix all service-layer violations")
3. **"Fix all"** — fix everything the AI can fix

When fixing:
- Show the proposed change as a diff before applying
- Apply changes file by file
- After each fix, briefly confirm what was changed
- Do NOT fix tool findings (those require the user to address config or code issues that tools flag) — only fix AI pattern findings where you can generate correct code

---

## Step 13: Baseline Management

The baseline tracks **deterministic tool findings only**. AI semantic findings are never baselined — they are report-only.

### Baseline file format

Path: the value from `codeguard.yaml` `baseline.path` (default: `.codeguard/baseline.json`)

```json
{
  "version": "1.0",
  "generated": "2026-03-21T14:30:00Z",
  "generatedBy": "codeguard-run",
  "module": "php-laravel",
  "entries": [
    {
      "tool": "larastan",
      "rule": "method.notFound",
      "file": "app/Services/OrderService.php",
      "message_normalized": "Call to undefined method * on *",
      "hash": "a1b2c3d4"
    }
  ]
}
```

### Hash computation

Each baseline entry's `hash` is a truncated SHA-256 of four fields concatenated with `|`:

```
sha256("larastan|method.notFound|app/Services/OrderService.php|Call to undefined method * on *")
```

Truncate to the first 8 hex characters.

### Message normalization

Before hashing, normalize the message to strip volatile content:
- Remove line numbers and column numbers (e.g., "on line 45" becomes "on line *")
- Replace specific type names with wildcards where they include generated or variable content
- The goal: the same conceptual violation produces the same hash even if the code moves within the file

### First run (no baseline exists)

After analysis completes:
1. Tell the user: "No baseline found. Would you like to create one from the current tool findings?"
2. If confirmed, generate `.codeguard/baseline.json` with all current **tool** findings as entries
3. Report: "Baseline created with {count} entries. These findings will be suppressed in future hook runs."

### Subsequent runs (baseline exists)

1. Load the existing baseline
2. For each tool finding, compute its hash and check against baseline entries
3. Separate findings into:
   - **Baselined** — hash matches an existing entry (suppress from report, show count in summary)
   - **New** — hash does not match any baseline entry (show in report as new violations)
4. After analysis, if there are new tool findings, ask the user:
   - "There are {count} new tool findings not in the baseline. Add them to the baseline, or keep as violations?"
   - If the user chooses to add: append new entries to the baseline, update the `generated` timestamp
   - If the user chooses to keep: leave the baseline unchanged. These will continue to appear as violations in hook runs.

### What the baseline does NOT cover

- AI semantic findings are **never** baselined. They appear in every run.
- The baseline is consumed by the hook runner during `git commit`. Baselined entries are suppressed from the pre-commit output.

---

## Error Handling

| Situation | Action |
|---|---|
| `codeguard.yaml` not found | Stop. Tell user to run `/codeguard-setup` |
| `CODEGUARD.md` not found | Continue without project context. Warn the user. |
| `.codeguard/modules/` not found | Stop. Tell user to run `npx @henryavila/codeguard install` |
| Tool binary not found | Should be caught by preflight (Step 5). If not, report with install command. Continue. |
| Tool crashes (non-zero exit with stderr) | Report **verbatim** output. Continue with other tools. |
| No patterns loaded | Warn but continue — tool analysis still runs |
| Baseline file corrupt/unparseable | Treat as empty baseline. Warn the user. |

### CRITICAL: No inference on tool errors

When any tool fails, crashes, or produces unexpected output:

1. **Report the literal output** — copy the exact stdout/stderr the tool produced
2. **NEVER infer, diagnose, or speculate about the cause** — do not say "probably because of X" or "likely caused by Y"
3. **NEVER fabricate error details** — if the tool says "segfault", report "segfault", not "missing configuration file"
4. **If the output is empty**, say: "{Tool} failed with exit code {N} and produced no output"

The user is an experienced developer. They can read error messages. Your job is to **relay**, not **interpret**.

---

## Notes

- The `ai-review` capability has no tool adapter and no hook involvement. It is always available through this skill — the AI performs semantic analysis directly using loaded patterns and ai-rules.
- Pint (formatting) is not run during `/codeguard-run`. Pint is an autofix tool that runs in the pre-commit hook. If the user wants to format, they run `vendor/bin/pint` directly.
- Output symbols match the hook runner output for consistency: `✗` for blocking, `⚠` for warning, `→` for suggestion.
- When running on staged changes, the scope may be empty (no staged PHP files). Report this and exit gracefully.
