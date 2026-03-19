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
| **2** | CLI Installer | `npx codeguard install` with 7 IDE support + placeholder skills | Phase 1 | Pending |
| **3** | Pattern Catalog Content | 28 YAML pattern files + ai-rules for core/php/laravel | Phase 1 | Pending |
| **4** | Tool Adapters + Hook Runner | Larastan/Pint/PHPMD/Pest adapters + pre-commit pipeline + bundling | Phase 1 | Pending |
| **5** | Skills | codeguard-setup.md, codeguard-run.md, codeguard-health.md | Phase 3 + 4 | Pending |

### Changes from original plan

1. **Phase 4 and 5 merged.** Original had adapters (Phase 4) and hook runner (Phase 5) as separate phases. Merged because adapters are useless without the hook runner that consumes them, and the hook runner is the only consumer. One phase delivers a working pre-commit hook.

2. **Phase 5 (Skills) renumbered to 5.** Was Phase 6. Now depends on Phase 3 (patterns must exist for skills to reference) AND Phase 4 (hook runner must exist for setup skill to install).

3. **Adapter location resolved.** Adapters live in `src/adapters/php-laravel/` (TypeScript, compiled by tsdown). Module YAML/markdown stays in `modules/` (static data, shipped as-is). This was discovered during Phase 1 — tsdown only compiles `src/`.

4. **Hook runner bundling resolved.** Hook runner is compiled during `npm run build` as a tsdown entry point. Ships as `dist/hook-runner.js` in the npm package. During setup, the AI copies it to `.codeguard/hook-runner.js`. No runtime compilation needed.

5. **Installer uses placeholder skills.** Phase 2 creates placeholder skill files (minimal markdown with "coming soon" message). Phase 5 replaces them with real skills. This allows Phase 2 to be independent.

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

Phases 2, 3, and 4 can run in parallel. Phase 5 needs both 3 and 4.

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
      index.ts          ← Barrel (30 types)
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

22 tests, all passing. Build clean. Lint clean.

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

Runtime: ajv, chalk, commander, deepmerge, yaml
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
  e2e/
    install-flow.test.ts
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
- ai-rules are markdown files with analysis heuristics, false positive prevention, severity classification
- Split work by layer: core (12 remaining), php (6), laravel (8 remaining)
- Also create `modules/php/` structure (patterns/ + ai-rules/) — doesn't exist yet

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
- Output formatter: tool-level messages (no pattern knowledge)
- Exit code logic: block violations → exit 1, only warnings → exit 0

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
  runner.ts              ← main pipeline (replace placeholder)
  staged-files.ts        ← get staged files via git diff
  baseline.ts            ← load and match baseline
  formatter.ts           ← format tool-level messages
  index.ts
tests/
  unit/adapters/
    larastan.test.ts     ← test with JSON output fixtures
    pint.test.ts
    phpmd.test.ts
    pest.test.ts
  unit/hooks/
    runner.test.ts
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
- Consider separate bundle config for hook runner with `deps.alwaysBundle` if needed

**Testing strategy:**
- Unit tests with fixture JSON/text outputs (no real PHP tools needed)
- Each adapter tested: buildCommand produces correct args, parseOutput normalizes correctly, filterToStaged filters correctly
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
- Phase 3 (patterns exist, so skill can reference them)
- Phase 4 (hook runner exists, so setup skill can install it)
- Phase 2 (installer exists, so skills are deployed to IDEs)

**This is the most important phase** — skills are what the user actually interacts with. They need to be tested manually by running them in Claude Code on a real Laravel project.

---

## Execution order recommendation

```
Now:     Phase 2 (Installer) — straightforward CLI work
Then:    Phase 3 (Patterns) — content creation, can be fast
Then:    Phase 4 (Adapters + Hook Runner) — most complex, needs fixture data
Finally: Phase 5 (Skills) — needs everything else done first
```

Or if parallel conversations are possible:
```
Session A: Phase 2 (Installer)     ─┐
Session B: Phase 3 (Patterns)      ─┤── Phase 5 (Skills)
Session C: Phase 4 (Adapters+Hook) ─┘
```
