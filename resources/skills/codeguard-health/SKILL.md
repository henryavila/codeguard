---
name: codeguard-health
description: Show project health overview — configuration, tools, baseline, patterns, hooks, drift, and recommendations
---

# /codeguard-health

You are an AI agent performing a read-only health assessment of a project's CodeGuard setup. You will inspect configuration files, check tool availability, evaluate baseline freshness, count active patterns, verify hook installation, detect drift, and present a concise scannable report with actionable recommendations.

**This skill makes NO changes to the project.** It only reads files and runs version-check commands.

## IDE Invocation

| IDE | How to invoke |
|---|---|
| Claude Code | `/codeguard-health` |
| OpenCode | `/codeguard-health` |
| Cursor | Mention `codeguard-health` in chat |
| Codex CLI | To be validated |
| Gemini CLI | To be validated |
| Copilot CLI | To be validated |
| Windsurf | To be validated |

---

## Prerequisites

Before starting, confirm these paths exist relative to project root:

| File | Required | Purpose |
|---|---|---|
| `codeguard.yaml` | Yes | Main configuration |
| `.codeguard/baseline.json` | No | Violation baseline |
| `.codeguard/hook-runner.js` | No | Pre-commit hook runner |
| `.git/hooks/pre-commit` | No | Git hook shim |
| `CODEGUARD.md` | No | AI context document |

If `codeguard.yaml` does not exist, stop immediately and report:

```
CodeGuard Health Report
=======================

  Configuration     ! codeguard.yaml not found

  Run /codeguard-setup to initialize CodeGuard for this project.
```

---

## Step 1: Read Configuration

1. Read `codeguard.yaml` from project root.
2. Parse it as YAML. Extract:
   - `version` — config schema version
   - `project.module` — detected module name (e.g., `php-laravel`)
   - `project.language` — language (e.g., `php`)
   - `project.framework` — framework (e.g., `laravel`)
   - `capabilities` — map of capability name to config (`enabled`, `enforcement`, `level`, `presets`)
   - `patterns.catalog` — list of framework-specific pattern names
   - `patterns.discovered` — list of discovered pattern names
   - `patterns.custom` — list of custom pattern names
   - `hooks.pre-commit.enabled` — whether hook is active
   - `baseline.path` — path to baseline file (default: `.codeguard/baseline.json`)
3. Validate the YAML parsed successfully. If malformed, report the error in the Configuration line and continue with remaining checks where possible.

---

## Step 2: Read Baseline

1. Check if the baseline file exists at the path from `baseline.path` (default `.codeguard/baseline.json`).
2. If it exists, read and parse it as JSON. Extract:
   - `generated` — ISO 8601 timestamp of when the baseline was created
   - `generatedBy` — what generated it (should be `codeguard-run`)
   - `module` — module name at generation time
   - `entries` — array of baseline entries
3. Calculate **baseline age** in days: difference between current date and the `generated` timestamp.
4. Count total entries: `entries.length`.
5. Identify **stale entries**: entries whose `file` path no longer exists on disk. Count them.

If the baseline file does not exist, record: "No baseline found."

---

## Step 3: Check Tool Status

For each capability in `codeguard.yaml` where `enabled: true`, check whether the corresponding tool binary is installed and get its version.

Use these binary paths based on the module. For `php-laravel`, read `.codeguard/modules/php-laravel/preset.yaml` if it exists, otherwise use these defaults from the module's `preset.yaml`:

| Capability | Tool | Binary | Version command |
|---|---|---|---|
| `static-analysis` | Larastan | `vendor/bin/phpstan` | `vendor/bin/phpstan --version` |
| `formatting` | Pint | `vendor/bin/pint` | `vendor/bin/pint --version` |
| `mess-detection` | PHPMD | `vendor/bin/phpmd` | `vendor/bin/phpmd --version` |
| `arch-testing` | Pest | `vendor/bin/pest` | `vendor/bin/pest --version` |

For each enabled tool:

1. Check if the binary file exists at the expected path.
2. If it exists, run the version command and capture stdout. Parse out the version number (e.g., `v2.1.0`, `1.21.0`).
3. If the binary does not exist, record it as missing with the install command from `preset.yaml`.
4. If the binary exists but the version command fails, record it as "installed but version check failed."

Also check for the Larastan level configured in `codeguard.yaml` — show it as `(L{level})` next to the tool name.

---

## Step 4: Check Hook Status

1. **Hook runner**: Check if `.codeguard/hook-runner.js` exists.
2. **Pre-commit hook**: Check if `.git/hooks/pre-commit` exists.
   - If it exists, read its contents and verify it references `.codeguard/hook-runner.js` (look for the string `hook-runner.js` or `.codeguard/hook-runner`).
   - If it exists but does NOT reference the CodeGuard hook runner, report it as "exists but not CodeGuard" (another tool may own it).
3. **Hook config**: Check `hooks.pre-commit.enabled` in `codeguard.yaml`. If `false`, note "disabled in config."

---

## Step 5: Count Active Patterns

Active patterns come from three layers, loaded based on the module hierarchy. For module `php-laravel` with `language: php`:

1. **Core patterns**: List YAML files in `.codeguard/modules/core/patterns/` (always loaded). Count them.
2. **Language patterns**: List YAML files in `.codeguard/modules/php/patterns/` (loaded for PHP projects). Count them.
3. **Framework patterns**: Count entries in `codeguard.yaml` `patterns.catalog` list.
4. **Discovered patterns**: Count entries in `patterns.discovered`. These are YAML files in `.codeguard/patterns/`.
5. **Custom patterns**: Count entries in `patterns.custom`. These are also YAML files in `.codeguard/patterns/`.

If `.codeguard/modules/` does not exist (modules not yet copied), fall back to counting pattern files from the CodeGuard npm package's `node_modules/@henryavila/codeguard/modules/` path if accessible, or report "Module data not installed."

Total active patterns = core + language + catalog + discovered + custom.

---

## Step 6: Check Arch Tests

1. Check if `tests/Architecture/CodeGuardArchTest.php` exists.
2. If it exists, read it and count the number of `arch()` or `test(` calls to estimate the assertion count.
3. Report the file path and assertion count.

If it does not exist, record: "No arch tests generated yet."

---

## Step 7: Drift Detection

Compare what `codeguard.yaml` declares with what `CODEGUARD.md` describes.

1. Read `CODEGUARD.md` from project root (if it exists).
2. For each enabled capability in `codeguard.yaml`, check if `CODEGUARD.md` mentions the tool name or capability name. Flag any enabled capability not mentioned in `CODEGUARD.md`.
3. Check if `CODEGUARD.md` mentions tools or capabilities that are NOT in `codeguard.yaml` or are disabled. Flag these as potential drift.
4. Compare the pattern names in `codeguard.yaml` `patterns.catalog` with patterns referenced in `CODEGUARD.md`. Flag mismatches.

If `CODEGUARD.md` does not exist, report: "CODEGUARD.md not found — run /codeguard-setup to generate."

**Drift is informational, not blocking.** Small drift is normal between setup runs.

---

## Step 8: Generate Recommendations

Based on findings from Steps 1-7, generate specific actionable recommendations. Each recommendation must include a concrete command or action. Priority order:

1. **Missing config** (critical): "Run /codeguard-setup to initialize CodeGuard"
2. **Missing tools** (high): "Install {tool}: {install_command}" — use the `install_commands` from `preset.yaml`
3. **Missing hook runner** (high): "Run /codeguard-setup to install hook runner"
4. **Missing pre-commit hook** (high): "Run /codeguard-setup to install git hook"
5. **Stale baseline** (medium): "Baseline is {N} days old — run /codeguard-run to refresh"
6. **No baseline** (medium): "No baseline found — run /codeguard-run to establish baseline"
7. **Stale baseline entries** (low): "{N} baseline entries reference files that no longer exist"
8. **Missing CODEGUARD.md** (medium): "Run /codeguard-setup to generate CODEGUARD.md"
9. **Drift detected** (low): "CODEGUARD.md and codeguard.yaml are out of sync — re-run /codeguard-setup"
10. **Missing arch tests** (medium): "Run /codeguard-setup to generate Pest arch tests"
11. **Hook disabled** (info): "Pre-commit hook is disabled in codeguard.yaml"

Limit to the 5 most important recommendations. If everything is healthy, say "No issues found."

---

## Step 9: Present the Report

Format the health report as a single clear output. Use these symbols:

- `[ok]` — healthy, no action needed
- `[!!]` — warning, action recommended
- `[FAIL]` — missing or broken, action required
- `[info]` — informational, no action needed

### Report Template

Follow this exact structure. Omit sections that have zero findings (e.g., if no drift, omit the Drift section). Adjust content per the actual findings.

```
CodeGuard Health Report
=======================

Configuration     [ok] codeguard.yaml found (module: php-laravel, v1.0)
Hook Runner       [ok] .codeguard/hook-runner.js installed
Pre-commit Hook   [ok] .git/hooks/pre-commit active
Baseline          [!!] 45 entries, last updated 15 days ago
                       3 stale entries (files no longer exist)

Tools
  Larastan (L6)   [ok] vendor/bin/phpstan v2.1.0
  Pint            [ok] vendor/bin/pint v1.21.0
  PHPMD           [FAIL] vendor/bin/phpmd not found
  Pest            [ok] vendor/bin/pest v3.7.0

Patterns          28 active (13 core + 6 PHP + 9 Laravel)
                  2 discovered, 1 custom
Arch Tests        [ok] tests/Architecture/CodeGuardArchTest.php (12 assertions)

Drift
  [!!] CODEGUARD.md mentions "rector" but it is not in codeguard.yaml

Recommendations
  1. Install PHPMD: composer require --dev phpmd/phpmd
  2. Baseline aging (15 days) — run /codeguard-run to refresh
  3. 3 stale baseline entries — consider refreshing baseline
```

### Fully Healthy Example (no recommendations)

```
CodeGuard Health Report
=======================

Configuration     [ok] codeguard.yaml found (module: php-laravel, v1.0)
Hook Runner       [ok] .codeguard/hook-runner.js installed
Pre-commit Hook   [ok] .git/hooks/pre-commit active
Baseline          [ok] 12 entries, last updated 2 days ago

Tools
  Larastan (L6)   [ok] vendor/bin/phpstan v2.1.0
  Pint            [ok] vendor/bin/pint v1.21.0
  PHPMD           [ok] vendor/bin/phpmd v2.15.0
  Pest            [ok] vendor/bin/pest v3.7.0

Patterns          28 active (13 core + 6 PHP + 9 Laravel)
Arch Tests        [ok] tests/Architecture/CodeGuardArchTest.php (12 assertions)

No issues found.
```

### Minimal Setup Example (just after install, before first run)

```
CodeGuard Health Report
=======================

Configuration     [ok] codeguard.yaml found (module: php-laravel, v1.0)
Hook Runner       [ok] .codeguard/hook-runner.js installed
Pre-commit Hook   [ok] .git/hooks/pre-commit active
Baseline          [info] No baseline found

Tools
  Larastan (L6)   [ok] vendor/bin/phpstan v2.1.0
  Pint            [ok] vendor/bin/pint v1.21.0
  PHPMD           [ok] vendor/bin/phpmd v2.15.0
  Pest            [ok] vendor/bin/pest v3.7.0

Patterns          28 active (13 core + 6 PHP + 9 Laravel)
Arch Tests        [ok] tests/Architecture/CodeGuardArchTest.php (12 assertions)

Recommendations
  1. No baseline found — run /codeguard-run to establish baseline
```

---

## Important Notes

- **Read-only**: This skill never modifies any file. It only reads files and runs `--version` commands.
- **Fast**: The entire health check should complete in a few seconds. No heavy analysis.
- **Baseline age thresholds**: Consider a baseline "fresh" if under 7 days, "aging" if 7-30 days, "stale" if over 30 days.
- **Tool version parsing**: Different tools output versions differently. `phpstan --version` outputs `PHPStan - PHP Static Analysis Tool 2.1.0`, `pint --version` outputs `Laravel Pint 1.21.0`, `phpmd --version` outputs `PHPMD 2.15.0`, `pest --version` outputs `Pest 3.7.0`. Extract just the semver portion.
- **Graceful degradation**: If any single check fails (file unreadable, command times out), report that specific check as failed and continue with the remaining checks. Never let one failure prevent the full report.
- **No codeguard.yaml is the only hard stop**: Every other missing file is reported as a finding, not an error.
