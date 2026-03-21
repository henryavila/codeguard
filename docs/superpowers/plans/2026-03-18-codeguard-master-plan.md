# CodeGuard — Master Implementation Plan

> **For agentic workers:** Each phase is a separate plan document with bite-sized tasks. Execute one phase per conversation session for context isolation.

**Goal:** Implement CodeGuard from approved design spec to working MVP

**Architecture:** Skills-first — npm package delivers installer + hook runner + skills + pattern catalog. AI agent does the heavy work via skills. Three-layer enforcement: deterministic tools, Pest arch tests, AI semantic analysis.

**Tech Stack:** TypeScript, Node.js 20+, ESM, tsdown, Vitest, Commander, yaml, chalk

**Design Spec:** `docs/superpowers/specs/2026-03-18-codeguard-design.md`

---

## Phase Overview

| Phase | Name | What it produces | Depends on | Status |
|---|---|---|---|---|
| **1** | Foundation — Types & Module System | Updated types, module loader, pattern loader, config loader | Story 1-1 | **DONE** |
| **2** | CLI Installer | `npx codeguard install` with 7 IDE support + placeholder skills | Phase 1 | **DONE** |
| **3** | Pattern Catalog Content | 28 YAML pattern files + ai-rules for core/php/laravel | Phase 1 | **DONE** |
| **4** | Tool Adapters + Hook Runner | Larastan/Pint/PHPMD/Pest adapters + pre-commit pipeline + bundling | Phase 1 | Pending |
| **5** | Skills | codeguard-setup.md, codeguard-run.md, codeguard-health.md | Phase 2 + 3 + 4 | Pending |

### Changes from original plan

1. **Phase 4 and 5 merged.** Original had adapters (Phase 4) and hook runner (Phase 5) as separate phases. Merged because adapters are useless without the hook runner that consumes them, and the hook runner is the only consumer. One phase delivers a working pre-commit hook.

2. **Phase 5 (Skills) renumbered to 5.** Was Phase 6. Now depends on Phase 3 (patterns) + Phase 4 (hook runner). Phase 2 (installer) is a soft dependency — skills can be written without the installer, but the installer deploys them to IDEs.

3. **Adapter location resolved.** Adapters live in `src/adapters/php-laravel/` (TypeScript, compiled by tsdown). Module YAML/markdown stays in `modules/` (static data, shipped as-is). This was discovered during Phase 1 — tsdown only compiles `src/`. Design spec Sections 2 and 4 have been updated to reflect this change.

4. **Hook runner bundling resolved.** Hook runner is compiled during `npm run build` as a tsdown entry point. Ships as `dist/hooks/runner.js` in the npm package. During setup, the AI copies it to `.codeguard/hook-runner.js`. No runtime compilation needed. **IMPORTANT:** tsdown must use `deps.alwaysBundle` for the hook runner entry to produce a self-contained bundle with zero external imports.

5. **`arch-tests/` directory dropped from module structure.** The setup skill generates Pest arch tests from scratch based on active patterns' verification rules — no templates needed. The `arch-tests/` directory shown in the design spec Section 4 is unnecessary.

6. **How setup skill locates hook runner from npm package.** The setup skill instructs the AI to run: `node -e "console.log(require.resolve('codeguard/dist/hooks/runner.js'))"` to find the pre-compiled hook runner in the installed npm package, then copy it to `.codeguard/hook-runner.js`. Alternative: if CodeGuard is invoked via `npx`, the skill can reference `$(npm root -g)/codeguard/dist/hooks/runner.js` or use `import.meta.resolve`.

7. **Installer uses placeholder skills.** Phase 2 creates placeholder skill files (minimal markdown with "coming soon" message). Phase 5 replaces them with real skills. This allows Phase 2 to be independent.

### Dependency graph (revised)

```
Phase 1: Foundation (DONE)
    │
    ├──────────┬───────────┐
    ▼          ▼           ▼
Phase 2    Phase 3     Phase 4
Installer  Patterns    Adapters + Hook Runner
               │           │
               ├───────────┘
               ▼
           Phase 5
           Skills
               │
               ▼
           MVP Done
```

Phases 2, 3, and 4 can run in parallel. Phase 5 needs Phase 3 + 4 (hard dependency) and Phase 2 (soft — skills can be written without installer, but installer deploys them).

---

## Current codebase (after Phase 1)

```
src/
  core/
    types/
      config.ts         ← CodeGuardConfig, Enforcement, CapabilityConfig, etc.
      violations.ts     ← AnalysisViolation (standard/reference optional), ToolResult
      modules.ts        ← ToolAdapter, CommandSpec, PatternDefinition, ModuleDefinition, PresetDefinition
      output.ts         ← FormatterContext (scope: hook|run|health), OutputFormatter
      result.ts         ← Generic Result<T> type
      index.ts          ← Barrel (29 types + Result)
    config/
      loader.ts         ← loadConfig(filePath) → Result<CodeGuardConfig>
      schema.ts         ← ajv JSON Schema (plain object, not JSONSchemaType)
      index.ts
    patterns/
      loader.ts         ← loadPattern(file), loadPatterns(dir) → PatternsResult with warnings
      index.ts
    modules/
      loader.ts         ← loadModuleDefinition, loadPresetDefinition
      registry.ts       ← discoverModules, findModuleForProject (ALL files + ANY dep)
      index.ts
  hooks/
    runner.ts           ← placeholder
  index.ts              ← public API (types only)
modules/
  core/patterns/
    single-responsibility.yaml
  php-laravel/
    module.yaml         ← 4 capabilities (larastan, pint, phpmd, pest)
    preset.yaml         ← tool binaries, configs, install commands
    patterns/
      service-layer.yaml
tests/
  fixtures/
    codeguard.yaml
    codeguard-invalid.yaml
  unit/core/
    types.test.ts       ← 8 tests
    config/loader.test.ts ← 3 tests
    patterns/loader.test.ts ← 4 tests
    modules/loader.test.ts  ← 3 tests
    modules/registry.test.ts ← 4 tests
```

39 tests, all passing. Build clean. Lint clean.
NOTE: Codebase section shows Phase 1 state. Phase 2 added src/cli/ + skills/. Phase 3 added 28 patterns + 3 ai-rules across modules/core/, modules/php/, modules/php-laravel/. See git log for current state.

### Technical patterns established

- `Result<T>` for all fallible operations (in `src/core/types/result.ts`)
- `PatternsResult` for operations with partial success (data + warnings)
- ajv import: `import { default as Ajv } from 'ajv'`
- ajv schema: plain `as const` object (NOT `JSONSchemaType<T>`)
- YAML files read with `{ encoding: 'utf-8' }` always
- Loaders follow: read → parse → validate → return Result
- `discoverModules` skips directories without module.yaml (core/ has no module.yaml — intentional)
- `additionalProperties: false` in ajv schemas for strict validation
- `type: 'integer'` for level/threshold values

### Dependencies (current)

Runtime: @inquirer/prompts, ajv, chalk, commander, deepmerge, yaml
Dev: typescript, tsdown, vitest, tsx, eslint, prettier, @types/node
Removed: handlebars, cosmiconfig

---

## Phase 2: CLI Installer

**Goal:** `npx codeguard install` displays IDE menu, copies placeholder skills to selected IDEs.

**Key decisions:**
- Add `@inquirer/prompts` for interactive checkbox
- 7 IDEs with different deployment mechanisms (copy, symlink, plugin hook) — follow BMAD patterns
- Placeholder skills (real content in Phase 5)
- Commander subcommand: `codeguard install`
- TTY check: exit with clear error if not interactive terminal

**Files to create:**
```
src/cli/
  index.ts              ← Commander program
  commands/install.ts   ← install command
  ide-registry.ts       ← IDE name/path/mechanism mapping
  skill-deployer.ts     ← copy/symlink skills per IDE mechanism
skills/
  codeguard-setup.md    ← placeholder
  codeguard-run.md      ← placeholder
  codeguard-health.md   ← placeholder
tests/
  unit/cli/
    ide-registry.test.ts
    skill-deployer.test.ts
    install.test.ts
    cli-entry.test.ts
```

**Build changes:**
- Add `'cli/index': 'src/cli/index.ts'` to tsdown entry points
- Update `bin/codeguard.js` to import from `dist/cli/index.js`

---

## Phase 3: Pattern Catalog Content

**Goal:** Create all 28 MVP pattern YAML files + ai-rules markdown for each layer.

**Key decisions:**
- 26 new patterns (2 already exist: single-responsibility, service-layer)
- Each follows the exact YAML schema validated in Phase 1
- **IMPORTANT:** Detection signals MUST use structured `{type, value}` format (e.g., `{type: 'directory', value: 'app/Services'}`), NOT the design spec's shorthand format (`directory: app/Services`). The structured format is what the Phase 1 loader validates.
- ai-rules are markdown files with analysis heuristics, false positive prevention, severity classification
- Split work by layer: core (12 remaining), php (6), laravel (8 remaining)
- Create `modules/php/` structure (patterns/ + ai-rules/) — doesn't exist yet
- **NOTE:** `modules/php/` intentionally has no `module.yaml`. Like `modules/core/`, PHP-layer patterns are loaded by the hierarchy logic in the setup skill (Phase 5), not by `discoverModules`. Only framework-specific modules (php-laravel, etc.) have `module.yaml`.

**Files to create:**
```
modules/
  core/
    patterns/              ← 12 new YAML files
      dry.yaml
      small-functions.yaml
      few-arguments.yaml
      consistent-error-handling.yaml
      separation-of-concerns.yaml
      no-long-switch.yaml
      no-constructor-many-params.yaml
      no-god-object.yaml
      no-deep-inheritance.yaml
      layer-dependency-direction.yaml
      no-circular-dependencies.yaml
      bounded-contexts.yaml
    ai-rules/
      core.md              ← universal analysis instructions
  php/
    patterns/              ← 6 new YAML files
      strict-typing.yaml
      no-html-in-php.yaml
      no-debug-functions.yaml
      type-declarations.yaml
      exception-handling.yaml
      no-superglobals.yaml
    ai-rules/
      php.md               ← PHP analysis instructions
  php-laravel/
    patterns/              ← 7 new YAML files (service-layer exists)
      dto.yaml
      form-requests.yaml
      action-classes.yaml
      value-objects.yaml
      resource-controllers.yaml
      policies.yaml
      no-env-outside-config.yaml
      no-logic-in-blade.yaml
    ai-rules/
      laravel.md           ← Laravel analysis instructions (content from brainstorming Gap 6)
```

**No code changes** — only YAML and markdown content. Tests validate files load correctly with existing loaders.

---

## Phase 4: Tool Adapters + Hook Runner

**Goal:** Working pre-commit hook that runs Larastan, Pint, PHPMD, Pest on staged files.

**Key decisions:**
- Adapters in `src/adapters/php-laravel/` (TypeScript, compiled by tsdown)
- Hook runner in `src/hooks/runner.ts` (replaces placeholder)
- Hook runner compiled as tsdown entry point → ships as `dist/hooks/runner.js`
- During setup (Phase 5), AI copies `dist/hooks/runner.js` to `.codeguard/hook-runner.js`
- Two-phase execution: Pint first (autofix), then Larastan + PHPMD + Pest in parallel
- Baseline matcher: loads `.codeguard/baseline.json`, filters known violations
- Output formatter: tool-level messages (no pattern knowledge). Format per design spec UX-DR (chalk colors, ✓/✗ symbols):
```
codeguard · pre-commit

  ✗ app/Services/OrderService.php:45
    Larastan: Call to undefined method calculateTotal()

  ⚠ app/Http/Controllers/OrderController.php:23
    PHPMD: CyclomaticComplexity - method has complexity of 15

  2 findings · 1 blocking · commit blocked
```
- Exit code logic: block violations → exit 1, only warnings → exit 0
- **Adapter resolution:** Hook runner imports all adapters at compile time (they are bundled). At runtime, reads `codeguard.yaml` capabilities, matches each enabled capability to its adapter via a lookup table in the runner (e.g., `'static-analysis' → larastanAdapter`, `'formatting' → pintAdapter`). Does NOT read module.yaml at runtime — the mapping is baked into the bundle for the Laravel module.
- **Config resolution:** The hook runner must merge `CapabilityConfig` (from codeguard.yaml) + `PresetTool` defaults (hardcoded in the runner for Laravel module) into a `ToolConfig` before passing to each adapter's `buildCommand`. This merge logic lives in the runner (e.g., `resolveToolConfig(capability: CapabilityConfig, preset: PresetTool): ToolConfig`). The preset defaults are embedded as constants in the runner bundle — no preset.yaml read at runtime.

**Files to create:**
```
src/adapters/
  php-laravel/
    larastan.ts          ← buildCommand, parseOutput, filterToStaged
    pint.ts
    phpmd.ts
    pest.ts
    index.ts             ← barrel export
src/hooks/
  runner.ts              ← main pipeline entry point (replace placeholder, tsdown builds this)
  staged-files.ts        ← get staged files via git diff
  baseline.ts            ← load and match baseline
  formatter.ts           ← format tool-level messages
  NOTE: NO index.ts barrel here — runner.ts IS the tsdown entry point
tests/
  unit/adapters/
    larastan.test.ts     ← test with JSON output fixtures
    pint.test.ts
    phpmd.test.ts
    pest.test.ts
  unit/hooks/
    runner.test.ts
    staged-files.test.ts   ← test git diff parsing with mock execFile
    baseline.test.ts
    formatter.test.ts
  fixtures/
    larastan-output.json ← real PHPStan JSON output samples
    pint-output.txt
    phpmd-output.json
    pest-output.txt
```

**Build changes:**
- tsdown entry: `'hooks/runner': 'src/hooks/runner.ts'` (already exists, needs real content)
- **MUST** split tsdown into two configs: one for library+CLI (deps external), one for hook runner (deps bundled)
- Hook runner tsdown config needs `noExternal: [/.*/]` or equivalent to inline all deps (yaml, ajv, chalk) into a single self-contained .js file
- Example separate config for hook runner:
```typescript
// tsdown.hook.config.ts
export default defineConfig({
  entry: { 'hooks/runner': 'src/hooks/runner.ts' },
  format: 'esm',
  outDir: 'dist',
  noExternal: [/.*/],  // bundle everything
  dts: false,           // no types needed for hook runner
});
```
- Update `package.json` scripts: `"build": "tsdown && tsdown --config tsdown.hook.config.ts"` or use a single config with per-entry overrides if tsdown supports it
- **VERIFY** after build: `dist/hooks/runner.js` must NOT contain bare `import` statements for `yaml`, `ajv`, `chalk` etc.
- **NOTE on Node.js built-ins:** `noExternal: [/.*/]` bundles npm deps but Node.js built-ins (`node:child_process`, `node:fs/promises`, `node:path`) remain as external imports (tsdown/rolldown handles this automatically). Verify `node:` imports are preserved in the built output.

**Baseline types (to be created in Phase 4):**
Phase 4 must create a `BaselineEntry` and `BaselineFile` type in `src/hooks/baseline.ts`:
```typescript
interface BaselineEntry {
  tool: string;
  rule: string;
  file: string;
  message_normalized: string;
  hash: string;
}

interface BaselineFile {
  version: string;
  generated: string;
  generatedBy: string;
  module: string;
  entries: BaselineEntry[];
}
```

**Baseline JSON schema (contract between Phase 4 and Phase 5):**
Phase 4's `baseline.ts` must parse this exact format. Phase 5's run skill must generate it.
```json
{
  "version": "1.0",
  "generated": "2026-03-18T10:30:00Z",
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

**Adapter-specific notes:**
- **Larastan:** Runs full project (`vendor/bin/phpstan analyse --error-format=json`), parseOutput reads PHPStan JSON format, filterToStaged keeps only violations in staged files
- **Pint:** Autofix-only in hook. The runner calls Pint DIRECTLY in Phase 1 (not via the standard ToolAdapter flow) because autofix is a special case: it modifies files + needs `git add`, and doesn't produce violations. The runner builds the command (`vendor/bin/pint {staged .php files}`), executes it, parses stdout to find which files were changed, and runs `git add` on those files. The Pint `ToolAdapter` is still created for consistency and potential future `--test` mode, but the Phase 1 autofix path bypasses `parseOutput` and `filterToStaged` since there are no violations to report.
- **PHPMD:** Runs on staged files only (`vendor/bin/phpmd {files} json {rulesets}`), parseOutput reads PHPMD JSON format
- **Pest:** Runs `vendor/bin/pest tests/Architecture/`. Output is NOT JSON — it's test output text. parseOutput must parse FAIL lines to extract violations (each failed arch test = one violation). Use `--colors=never` for parseable output.

**Testing strategy:**
- Unit tests with fixture JSON/text outputs (no real PHP tools needed)
- Each adapter tested: buildCommand produces correct args, parseOutput normalizes correctly, filterToStaged filters correctly
- **Pest adapter needs special attention:** test fixtures must include both passing and failing arch test output text
- Hook pipeline tested: staged files → phase 1 (pint) → phase 2 (parallel) → baseline → format → exit code

---

## Phase 5: Skills

**Goal:** Three complete skill markdown files that AI agents use to execute setup, run, and health.

**Key decisions:**
- Skills are markdown with YAML frontmatter (Agent Skills standard)
- Content follows the flow outlines from design spec Section 12
- Skills reference patterns, ai-rules, and codeguard.yaml by path
- Each skill includes IDE invocation table
- Skills are the PRODUCT — they need to be comprehensive, precise, and well-structured

**Files to create:**
```
skills/
  codeguard-setup.md     ← replace placeholder from Phase 2
  codeguard-run.md       ← replace placeholder
  codeguard-health.md    ← replace placeholder
```

**Dependencies on other phases:**
- Phase 2 (installer exists, so skills are deployed to IDEs)
- Phase 3 (patterns exist, so skills can reference them)
- Phase 4 (hook runner exists, so setup skill can install it)

**Setup skill MUST instruct the AI to create these artifacts:**
1. `.codeguard/` directory in project root
2. `.codeguard/hook-runner.js` — copy from CodeGuard npm package (`dist/hooks/runner.js`)
3. `.git/hooks/pre-commit` — shell shim (3-4 lines) that invokes `.codeguard/hook-runner.js`
4. `codeguard.yaml` — based on validated config from setup conversation
5. `CODEGUARD.md` — AI-generated, project-specific
6. `tests/Architecture/CodeGuardArchTest.php` — Pest arch tests from active patterns
7. IDE context file reference (e.g., append to CLAUDE.md) — `<!-- codeguard:start -->` markers

**Run skill MUST support:**
1. Running static analysis tools (calling shell commands)
2. AI pattern analysis against active patterns
3. Generating/updating `.codeguard/baseline.json` (format specified in Phase 4 section)

**This is the most important phase** — skills are what the user actually interacts with. They need to be tested manually by running them in Claude Code on a real Laravel project.

---

## Execution order

```
Phase 1: Foundation (DONE)
Phase 2: CLI Installer (DONE)
Phase 3: Pattern Catalog (DONE)
Phase 4: Adapters + Hook Runner ← NEXT
Phase 5: Skills ← after Phase 4
```
