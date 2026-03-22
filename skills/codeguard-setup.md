---
name: codeguard-setup
description: Configure project standards by analyzing codebase and generating governance configuration
---

# /codeguard-setup

| IDE | How to invoke |
|---|---|
| Claude Code | `/codeguard-setup` |
| OpenCode | `/codeguard-setup` |
| Cursor | Mention "codeguard-setup" in chat |
| Codex CLI | To be validated |
| Gemini CLI | To be validated |
| Copilot CLI | To be validated |
| Windsurf | To be validated |

## Overview

This skill analyzes a project's codebase, detects the technology stack, identifies architectural patterns in use, and generates the governance configuration files that CodeGuard uses for enforcement. It produces: `codeguard.yaml`, `CODEGUARD.md`, Pest arch tests, and a git pre-commit hook.

If the project already has a `codeguard.yaml`, this skill runs in **update mode** -- it detects drift between the config and the codebase, and regenerates affected files.

## Prerequisites

- The project must be a git repository (`git rev-parse --git-dir` succeeds)
- `npx codeguard install` must have been run already, which creates:
  - `.codeguard/hook-runner.js` (copied from the npm package)
  - `.codeguard/modules/` (module data copied from the npm package)
- PHP projects: `composer` must be available on PATH

## Instructions

### Step 1 -- Detect existing configuration

Check whether `codeguard.yaml` exists in the project root.

- If it exists: switch to **Update Mode** (see section below) after completing Steps 2-4
- If it does not exist: continue with first-time setup (Steps 2-15)

### Step 2 -- Scan available modules

Read all `module.yaml` files from `.codeguard/modules/`:

```bash
find .codeguard/modules -name "module.yaml" -type f
```

For each `module.yaml`, parse the YAML and store: `name`, `label`, `language`, `framework`, `detection`, `capabilities`.

### Step 3 -- Detect stack

For each discovered module, run its detection heuristics against the project:

1. **File check**: verify each file in `detection.files` exists in the project root
2. **Dependency check**: verify each entry in `detection.dependencies` appears in the project's dependency manifest (e.g., `composer.json` `require` or `require-dev`)
3. **Confidence**: if ALL file checks AND at least one dependency match, detection confidence is `high`. If only files match, confidence is `medium`.

If multiple modules match, prefer the one with `confidence: high`. If still tied, ask the developer to choose.

Present the detection result to the developer:

```
Detected stack: Laravel (php-laravel)
  Evidence:
    - composer.json exists
    - artisan exists
    - laravel/framework found in composer.json
  Confidence: high

Is this correct? [Y/n]
```

If the developer says no, list all available modules and let them choose.

### Step 4 -- Resolve module hierarchy

Using the detected module's `language` field, resolve the full pattern chain:

1. `core/` -- always loaded (universal patterns)
2. `{language}/` -- loaded if `.codeguard/modules/{language}/` directory exists (e.g., `php/`)
3. `{language}-{framework}/` -- the detected leaf module (e.g., `php-laravel/`)

Load all pattern YAML files from each layer's `patterns/` directory, in order. If a pattern name exists at multiple layers, the most specific layer wins (leaf > language > core).

Categorize loaded patterns into:
- **Core patterns**: from `core/` -- always active, not user-configurable
- **Language patterns**: from `{language}/` -- always active, not user-configurable
- **Framework patterns**: from `{language}-{framework}/` -- user selects which to activate

### Step 5 -- Phase 1: Recognition

Present all detected patterns to the developer, grouped by layer.

For each framework-layer pattern, check its `detection.signals` against the project:
- `type: directory` -- check if directory exists
- `type: file` -- check if matching files exist (glob)
- `type: import` -- search PHP files for matching use/import statements

Present findings:

```
=== Active Patterns ===

Core (always active, 13 patterns):
  - single-responsibility, dry, small-functions, few-arguments,
    consistent-error-handling, separation-of-concerns, no-long-switch,
    no-constructor-many-params, no-god-object, no-deep-inheritance,
    layer-dependency-direction, no-circular-dependencies, bounded-contexts

PHP (always active, 6 patterns):
  - strict-typing, no-html-in-php, no-debug-functions, type-declarations,
    exception-handling, no-superglobals

Laravel (detected with evidence):
  [x] service-layer -- app/Services/ directory found
  [x] dto -- Spatie\LaravelData\Data import found
  [ ] form-requests -- no app/Http/Requests/ directory found
  [x] action-classes -- app/Actions/ directory found
  ...
```

Patterns with detection evidence are pre-selected. Patterns without evidence are unchecked but available.

### Step 6 -- Phase 2: Discovery

Scan the codebase for architectural patterns NOT in the catalog. Look for:

- Recurring structural conventions (e.g., `app/Repositories/`, `app/Enums/`, `app/Events/`)
- Naming patterns (e.g., all services end with `Service`, all actions end with `Action`)
- Custom architectural rules the project follows

For each discovered pattern, create a pattern YAML file at `.codeguard/patterns/{name}.yaml` using the standard schema:

```yaml
name: result-objects
description: Service methods return Result objects instead of throwing exceptions
category: custom
layer: laravel
severity: warning
classification: discovered

detection:
  signals:
    - type: directory
      value: app/Results
    - type: import
      value: "App\\Results\\*"
  confidence: medium

verification:
  rules:
    - service methods should return Result objects for operations that can fail
    - exceptions should be reserved for truly exceptional circumstances

examples:
  correct: |
    public function processOrder(OrderData $data): OrderResult
    {
        // returns Result with success/failure
    }
  violation: |
    public function processOrder(OrderData $data): Order
    {
        // throws exception on business rule failure
    }
```

Present discovered patterns to the developer for approval.

### Step 7 -- Phase 3: Control

Let the developer adjust the pattern selection:

1. **Framework patterns**: add or remove from the detected list
2. **Discovered patterns**: confirm, edit, or discard
3. **Custom patterns**: developer can describe a pattern they want enforced -- create the YAML for it in `.codeguard/patterns/`

Core and language patterns cannot be removed -- they represent fundamental quality standards.

### Step 8 -- Configure capabilities

Read `capabilities` from the detected module's `module.yaml`. For each capability, ask the developer to configure:

- **enabled**: true/false (default: true for all)
- **enforcement**: block, warn, or autofix
- **level** (static-analysis only): integer level (default from `module.yaml`, e.g., 6 for Larastan)

Enforcement constraints:
- `autofix` is only valid for `formatting` (Pint). If the developer tries to set autofix on another capability, warn them and default to `block`.
- Recommend: `static-analysis: block`, `formatting: autofix`, `mess-detection: warn`, `arch-testing: block`

Present defaults and let the developer accept or customize:

```
Capabilities:
  static-analysis (Larastan): enabled, level 6, enforcement: block
  formatting (Pint): enabled, preset: laravel, enforcement: autofix
  mess-detection (PHPMD): enabled, enforcement: warn
  arch-testing (Pest): enabled, enforcement: block

Accept defaults? [Y/n] or specify changes:
```

Also configure thresholds:

```
Thresholds:
  max_method_lines: 20
  max_indentation_levels: 2

Accept defaults? [Y/n]
```

### Step 9 -- Install tools

Read `install_commands` from the detected module's `preset.yaml` at `.codeguard/modules/{module}/preset.yaml`.

For each tool, check if already installed (check `composer.json` require-dev). Only install missing tools:

```bash
composer require --dev larastan/larastan
composer require --dev laravel/pint
composer require --dev phpmd/phpmd
composer require --dev pestphp/pest
```

If a tool install fails, warn the developer but continue. Record which tools are installed.

### Step 10 -- Generate codeguard.yaml

Write `codeguard.yaml` to the project root. Only framework-specific patterns appear in `patterns.catalog`. Core and language patterns are loaded automatically by the module hierarchy and are NOT listed.

```yaml
version: "1.0"

project:
  language: php
  framework: laravel
  module: php-laravel

capabilities:
  static-analysis:
    enabled: true
    level: 6
    enforcement: block
  formatting:
    enabled: true
    enforcement: autofix
  mess-detection:
    enabled: true
    enforcement: warn
  arch-testing:
    enabled: true
    enforcement: block
    presets: [php, laravel]

patterns:
  catalog:
    - service-layer
    - dto
    - form-requests
    - action-classes
    - value-objects
    - resource-controllers
    - policies
    - no-env-outside-config
    - no-logic-in-blade
  discovered:
    # names of patterns in .codeguard/patterns/ confirmed during discovery
  custom:
    # names of custom patterns in .codeguard/patterns/ added by developer

thresholds:
  max_method_lines: 20
  max_indentation_levels: 2

hooks:
  pre-commit:
    enabled: true

baseline:
  path: .codeguard/baseline.json
```

Fields:
- `patterns.catalog` -- only framework-layer patterns the developer activated in Step 7
- `patterns.discovered` -- names of discovered patterns confirmed in Step 6 (YAML files in `.codeguard/patterns/`)
- `patterns.custom` -- names of custom patterns added in Step 7 (YAML files in `.codeguard/patterns/`)
- `capabilities.arch-testing.presets` -- from `module.yaml` `capabilities.arch-testing.presets` (e.g., `[php, laravel]`). This field is used only at setup time to generate Pest arch tests; the hook runner ignores it.

### Step 11 -- Generate CODEGUARD.md

Write `CODEGUARD.md` to the project root. This file is NOT templated -- write project-specific content based on what you learned during the setup conversation. AI IDEs read this file automatically as context during code generation.

The file MUST include these sections:

1. **Project Architecture** -- describe the project's layered structure based on detected patterns (e.g., "Controllers delegate to Services, Services contain business logic, DTOs transfer data between layers")
2. **Active Patterns** -- list all active patterns (core + language + framework + discovered + custom) with a one-line description of each
3. **Naming Conventions** -- document naming conventions observed in the codebase (e.g., "Services in `app/Services/`, suffixed with `Service`")
4. **File Organization** -- describe directory structure and where different types of code live
5. **Tool Configuration** -- summarize which tools are active, their enforcement levels, and key settings
6. **Code Generation Guidelines** -- concrete rules the AI must follow when generating code for this project (derived from active patterns)

Write in second person ("You must...") since the audience is an AI generating code.

### Step 12 -- Generate Pest arch tests

Write `tests/Architecture/CodeGuardArchTest.php`. Create the `tests/Architecture/` directory if it does not exist.

The file contains two kinds of tests:

**1. Preset calls** from `capabilities.arch-testing.presets`:

For each preset in the array (e.g., `[php, laravel]`), emit:

```php
arch()->preset()->php();
arch()->preset()->laravel();
```

**2. Custom arch rules** from active patterns' verification rules:

For each active pattern (all layers), examine each verification rule. Classify:

| Goes to Pest (deterministic) | Stays AI-only (semantic) |
|---|---|
| Rules about namespaces, imports, class types | Rules about intent or behavior |
| Rules about dependencies between layers | Rules about code quality |
| Rules about naming conventions | Rules about missing abstractions |
| Expressible as `toOnlyUse`, `toNotDependOn`, `toExtend`, `toImplement`, `toHavePrefix`, `toHaveSuffix`, `toBeClasses`, `toBeInterfaces`, `toBeEnums` | Everything else |

Only generate Pest rules for deterministic rules. Examples of pattern-to-Pest translation:

- "controllers must not access Eloquent models directly" -->
  `arch()->expect('App\Http\Controllers')->toNotDependOn('Illuminate\Database\Eloquent')`
- "services must not return HTTP responses" -->
  `arch()->expect('App\Services')->toNotDependOn('Illuminate\Http')`
- "services must not access Request object" -->
  `arch()->expect('App\Services')->toNotDependOn('Illuminate\Http\Request')`
- "no env() calls outside of config/" -->
  `arch()->expect('App')->toNotUse('env')` (if Pest supports this, otherwise AI-only)

Rules like "controllers must not contain business logic" or "methods should be short and focused" are AI-only -- skip them.

Generate the full PHP file:

```php
<?php declare(strict_types=1);

/**
 * CodeGuard Architecture Tests
 *
 * Auto-generated by /codeguard-setup. Edit codeguard.yaml and re-run setup to regenerate.
 * Manual edits will be overwritten.
 */

// Preset tests
arch()->preset()->php();
arch()->preset()->laravel();

// Pattern: service-layer
arch('controllers must not depend on Eloquent')
    ->expect('App\Http\Controllers')
    ->toNotDependOn('Illuminate\Database\Eloquent');

arch('services must not depend on HTTP layer')
    ->expect('App\Services')
    ->toNotDependOn('Illuminate\Http');

arch('services must not access Request')
    ->expect('App\Services')
    ->toNotDependOn('Illuminate\Http\Request');

// Pattern: form-requests
arch('controllers should use form requests')
    ->expect('App\Http\Controllers')
    ->toNotDependOn('Illuminate\Validation');

// ... additional deterministic rules from other active patterns
```

Adapt the namespace expectations to match the project's actual directory structure. If the project uses `app/Actions/` instead of `app/Services/`, adjust the `expect()` namespaces accordingly.

### Step 13 -- Verify hook runner

Verify that `.codeguard/hook-runner.js` exists (it should have been copied by `npx codeguard install`):

```bash
test -f .codeguard/hook-runner.js && echo "Hook runner found" || echo "Hook runner missing"
```

If missing, attempt to locate it and copy:

```bash
cp node_modules/codeguard/dist/hooks/runner.js .codeguard/hook-runner.js
```

If that also fails, warn the developer:

```
WARNING: Hook runner not found. Run `npx codeguard install` to set it up.
The pre-commit hook will not work until the hook runner is in place.
```

### Step 14 -- Install git hook

Write the pre-commit shell shim to `.git/hooks/pre-commit`:

```bash
#!/bin/sh
exec node "$(dirname "$0")/../.codeguard/hook-runner.js"
```

Make it executable:

```bash
chmod +x .git/hooks/pre-commit
```

If `.git/hooks/pre-commit` already exists, check its contents:
- If it already contains the CodeGuard shim, leave it alone
- If it contains other hook content, append the CodeGuard invocation or warn the developer about the conflict and let them decide

### Step 15 -- Show setup summary

Present a summary of everything that was configured:

```
=== CodeGuard Setup Complete ===

Module: php-laravel (Laravel)
Patterns: 28 active (13 core + 6 php + 9 laravel)
Discovered: 1 (result-objects)

Capabilities:
  static-analysis: Larastan level 6 [block]
  formatting: Pint (laravel preset) [autofix]
  mess-detection: PHPMD [warn]
  arch-testing: Pest [block]

Files created:
  codeguard.yaml        -- project configuration
  CODEGUARD.md          -- AI context document
  tests/Architecture/CodeGuardArchTest.php -- Pest arch tests (X deterministic rules)
  .git/hooks/pre-commit -- git hook (shell shim)

Files verified:
  .codeguard/hook-runner.js -- pre-commit hook runner

Next steps:
  1. Review codeguard.yaml and CODEGUARD.md
  2. Run `vendor/bin/pest tests/Architecture/` to validate arch tests
  3. Make a commit to test the pre-commit hook
  4. Run /codeguard-run for a full AI-powered analysis
```

### Step 16 -- Update IDE context file

If the project uses an IDE that reads a context file (e.g., `CLAUDE.md` for Claude Code), append a reference to `CODEGUARD.md` using marker comments so it can be updated on re-runs:

```markdown
<!-- codeguard:start -->
Read CODEGUARD.md for this project's architecture patterns and code generation guidelines.
When generating code, follow the patterns and conventions described there.
<!-- codeguard:end -->
```

If the markers already exist, replace the content between them. If the context file does not exist, create it with just the CodeGuard block.

## Update Mode

When `codeguard.yaml` already exists, the skill runs in update mode after completing Steps 2-4 (scan modules, detect stack, resolve hierarchy, load patterns).

### Step U1 -- Load existing configuration

Read the existing `codeguard.yaml` and parse it.

### Step U2 -- Detect drift

Compare the current codebase state against the existing config:

1. **New patterns available**: patterns in the module catalog that are not in `codeguard.yaml` (e.g., a CodeGuard update added new patterns)
2. **Missing patterns**: patterns in `codeguard.yaml` that no longer exist in the module (e.g., a pattern was renamed or removed)
3. **Detection changes**: patterns whose detection signals now match (or no longer match) the codebase
4. **New discovered patterns**: AI re-scans for patterns beyond the catalog that may have emerged since last setup
5. **Capability changes**: new capabilities available in updated module, or module defaults changed

### Step U3 -- Present drift report

```
=== CodeGuard Configuration Drift ===

New patterns available:
  + new-pattern-name -- description (added in CodeGuard vX.Y)

Detection changes:
  + form-requests -- app/Http/Requests/ directory now exists (was unchecked)
  - action-classes -- app/Actions/ directory no longer exists (was active)

Newly discovered patterns:
  + query-scopes -- QueryScope classes found in app/Scopes/

No capability changes.
```

### Step U4 -- Developer validates

Let the developer accept, reject, or modify each change. Only apply confirmed changes.

### Step U5 -- Regenerate affected files

Based on what changed:

- If patterns changed: regenerate `codeguard.yaml` `patterns` section, `CODEGUARD.md`, and `tests/Architecture/CodeGuardArchTest.php`
- If capabilities changed: regenerate `codeguard.yaml` `capabilities` section and `CODEGUARD.md` tool section
- If thresholds changed: update `codeguard.yaml` `thresholds` section
- Always re-verify hook runner and git hook (Steps 13-14)
- Update IDE context file markers (Step 16)

Show a summary of what was updated.
