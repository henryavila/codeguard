# CodeGuard — Master Implementation Plan

> **For agentic workers:** Each phase is a separate plan document with bite-sized tasks. Execute one phase per conversation session for context isolation.

**Goal:** Implement CodeGuard from approved design spec to working MVP

**Architecture:** Skills-first — npm package delivers installer + hook runner + skills + pattern catalog. AI agent does the heavy work via skills. Three-layer enforcement: deterministic tools, Pest arch tests, AI semantic analysis.

**Tech Stack:** TypeScript, Node.js 20+, ESM, tsdown, Vitest, Commander, yaml, chalk

**Design Spec:** `docs/superpowers/specs/2026-03-18-codeguard-design.md`

---

## Phase Overview

| Phase | Name | What it produces | Depends on |
|---|---|---|---|
| **1** | Foundation — Types & Module System | Updated types, module loader, pattern loader, config loader | Story 1-1 (done) |
| **2** | CLI Installer | `npx codeguard install` with 7 IDE support | Phase 1 |
| **3** | Pattern Catalog Content | 28 YAML pattern files + ai-rules for core/php/laravel | Phase 1 |
| **4** | Tool Adapters | Larastan, Pint, PHPMD, Pest adapters (ToolAdapter interface) | Phase 1 |
| **5** | Hook Runner | Pre-commit pipeline: phases, baseline, exit codes, bundling | Phase 1 + 4 |
| **6** | Skills | codeguard-setup.md, codeguard-run.md, codeguard-health.md | Phase 1 + 3 |

### Dependency graph

```
Story 1-1 (done)
    │
    ▼
Phase 1: Types + Module System
    │
    ├──────────┬───────────┐
    ▼          ▼           ▼
Phase 2    Phase 3     Phase 4
Installer  Patterns    Adapters
    │          │           │
    │          │           ▼
    │          │      Phase 5
    │          │      Hook Runner
    │          │           │
    │          ├───────────┘
    │          ▼
    │      Phase 6
    │      Skills
    │          │
    └──────────┘
         ▼
       MVP Done
```

Phases 2, 3, and 4 can run in parallel after Phase 1. Phase 5 needs Phase 4. Phase 6 needs Phase 3.

### Execution strategy

Each phase = 1 conversation with clean context. At the start of each phase conversation:
1. Read this master plan
2. Read the design spec
3. Read the phase-specific plan
4. Read existing code relevant to the phase

Between phases: code review validates the completed phase before starting the next.

---

## Phase plans

- **Phase 1:** `docs/superpowers/plans/2026-03-18-phase-1-foundation.md` ← DETAILED BELOW
- **Phase 2:** `docs/superpowers/plans/phase-2-installer.md` ← to be detailed before execution
- **Phase 3:** `docs/superpowers/plans/phase-3-patterns.md` ← to be detailed before execution
- **Phase 4:** `docs/superpowers/plans/phase-4-adapters.md` ← to be detailed before execution
- **Phase 5:** `docs/superpowers/plans/phase-5-hook-runner.md` ← to be detailed before execution
- **Phase 6:** `docs/superpowers/plans/phase-6-skills.md` ← to be detailed before execution

---

## Existing code (Story 1-1)

```
src/
  core/types/
    config.ts       ← needs update (new design)
    violations.ts   ← needs update (optional fields)
    modules.ts      ← needs update (new ToolAdapter, remove getTemplate)
    output.ts       ← needs update (new scope values)
    index.ts        ← barrel re-export
  hooks/
    runner.ts       ← placeholder
  index.ts          ← main entry re-exports
tests/
  unit/core/
    types.test.ts   ← needs update for new types
bin/
  codeguard.js      ← CLI entry point
```

Build, lint, test all passing. TypeScript strict mode. ESM only.

---

## Dependencies to add/remove

| Action | Package | Reason |
|---|---|---|
| Keep | commander, yaml, chalk, ajv, deepmerge | Used by installer, config loader, hook runner |
| Remove | handlebars | CODEGUARD.md is AI-generated, not template-based |
| Remove | cosmiconfig | Config is always `codeguard.yaml` — direct yaml parse is simpler |
| Add | @inquirer/prompts | IDE selection in installer (Phase 2) |

**Note on cosmiconfig:** The original architecture used cosmiconfig for multi-format loading (yaml, json, package.json). The new design standardizes on `codeguard.yaml` only. Direct `yaml` parse is simpler and avoids the cosmiconfig dependency. If multi-format support is needed later, cosmiconfig can be re-added.
