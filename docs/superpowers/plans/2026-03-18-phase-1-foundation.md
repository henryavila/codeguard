# Phase 1: Foundation — Types & Module System

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Update existing types to match the new design and build the module/pattern/config loaders that all subsequent phases depend on.

**Architecture:** Type-first approach. Update type contracts, then build loaders that parse YAML files into those types. Everything is pure functions with Result types — no side effects, no I/O in business logic.

**Tech Stack:** TypeScript, Vitest, yaml package, ajv for config validation

**Design Spec:** `docs/superpowers/specs/2026-03-18-codeguard-design.md`
**Master Plan:** `docs/superpowers/plans/2026-03-18-codeguard-master-plan.md`

---

## File Structure

### Files to modify

| File | What changes |
|---|---|
| `src/core/types/config.ts` | New `CodeGuardConfig` structure with capabilities, patterns (catalog/discovered/custom), thresholds. `enforcement` gets `'autofix'` value. |
| `src/core/types/violations.ts` | `standard` and `reference` become optional. |
| `src/core/types/modules.ts` | New `ToolAdapter` (buildCommand/parseOutput/filterToStaged), new `CommandSpec`, remove `getTemplate()`, new `PatternDefinition` matching YAML schema. |
| `src/core/types/output.ts` | `scope` becomes `'hook' \| 'run' \| 'health'`. |
| `src/core/types/index.ts` | Re-export new types. |
| `src/index.ts` | Re-export new types. |
| `tests/unit/core/types.test.ts` | Update tests for new type shapes. |
| `package.json` | Remove `handlebars`, remove `cosmiconfig`. |

### Files to create

| File | Responsibility |
|---|---|
| `src/core/config/loader.ts` | Load and parse `codeguard.yaml` → `CodeGuardConfig` |
| `src/core/config/schema.ts` | JSON Schema for codeguard.yaml validation via ajv |
| `src/core/config/index.ts` | Barrel export |
| `src/core/modules/loader.ts` | Load module.yaml + preset.yaml → module metadata |
| `src/core/modules/registry.ts` | Module registry — find and load modules by detection |
| `src/core/modules/index.ts` | Barrel export |
| `src/core/patterns/loader.ts` | Load pattern YAML files → `PatternDefinition[]` |
| `src/core/patterns/index.ts` | Barrel export |
| `modules/core/patterns/single-responsibility.yaml` | First pattern (used for testing) |
| `modules/php-laravel/module.yaml` | Laravel module identity and detection |
| `modules/php-laravel/preset.yaml` | Laravel tool defaults |
| `modules/php-laravel/patterns/service-layer.yaml` | First Laravel pattern (used for testing) |
| `tests/unit/core/config/loader.test.ts` | Config loader tests |
| `tests/unit/core/modules/loader.test.ts` | Module loader tests |
| `tests/unit/core/modules/registry.test.ts` | Module registry tests |
| `tests/unit/core/patterns/loader.test.ts` | Pattern loader tests |
| `tests/fixtures/codeguard.yaml` | Valid config fixture |
| `tests/fixtures/codeguard-invalid.yaml` | Invalid config fixture |

---

## Task 1: Update Type Contracts

**Files:**
- Modify: `src/core/types/config.ts`
- Modify: `src/core/types/violations.ts`
- Modify: `src/core/types/modules.ts`
- Modify: `src/core/types/output.ts`
- Modify: `src/core/types/index.ts`
- Modify: `src/index.ts`
- Modify: `tests/unit/core/types.test.ts`
- Test: `tests/unit/core/types.test.ts`

- [ ] **Step 1: Write failing tests for new type shapes**

```typescript
// tests/unit/core/types.test.ts — replace entire file
import { describe, it, expect } from 'vitest';

import type {
  Severity,
  AnalysisViolation,
  ToolError,
  ToolResult,
  AnalysisResult,
  ToolConfig,
  CapabilityConfig,
  PatternsConfig,
  HookConfig,
  BaselineConfig,
  CodeGuardConfig,
  DetectionResult,
  PatternDefinition,
  ModuleDefinition,
  ToolAdapter,
  CommandSpec,
  FormatterContext,
  OutputFormatter,
  Enforcement,
} from '../../../src/core/types/index.js';

describe('Core Types', () => {
  it('should support autofix enforcement', () => {
    const enforcement: Enforcement = 'autofix';
    expect(['block', 'warn', 'autofix']).toContain(enforcement);
  });

  it('should have optional standard and reference on AnalysisViolation', () => {
    const violation: AnalysisViolation = {
      tool: 'larastan',
      rule: 'missingType',
      severity: 'warning',
      file: 'src/Foo.php',
      line: 10,
      message: 'Missing return type',
    };
    expect(violation.tool).toBe('larastan');
    expect(violation.standard).toBeUndefined();
    expect(violation.reference).toBeUndefined();
  });

  it('should support structured CodeGuardConfig', () => {
    const config: CodeGuardConfig = {
      version: '1.0',
      project: { language: 'php', framework: 'laravel', module: 'php-laravel' },
      capabilities: {
        'static-analysis': { enabled: true, enforcement: 'block', level: 6 },
        formatting: { enabled: true, enforcement: 'autofix' },
      },
      patterns: {
        catalog: ['service-layer'],
        discovered: [],
        custom: [],
      },
      thresholds: { max_method_lines: 20, max_indentation_levels: 2 },
      hooks: { 'pre-commit': { enabled: true, scope: 'staged-files' } },
      baseline: { path: '.codeguard/baseline.json' },
    };
    expect(config.version).toBe('1.0');
    expect(config.capabilities['static-analysis'].enforcement).toBe('block');
  });

  it('should export ToolAdapter with buildCommand and parseOutput', () => {
    const adapter: ToolAdapter = {
      name: 'larastan',
      binary: 'vendor/bin/phpstan',
      buildCommand: (files, config) => ({
        binary: 'vendor/bin/phpstan',
        args: ['analyse', '--error-format=json', ...files],
      }),
      parseOutput: (raw) => ({ success: true, violations: [] }),
      filterToStaged: (violations, staged) => violations,
    };
    expect(adapter.name).toBe('larastan');
    const cmd = adapter.buildCommand(['file.php'], {} as ToolConfig);
    expect(cmd.binary).toBe('vendor/bin/phpstan');
  });

  it('should export PatternDefinition matching YAML schema', () => {
    const pattern: PatternDefinition = {
      name: 'service-layer',
      description: 'Controllers delegate business logic to Services',
      category: 'architecture',
      layer: 'laravel',
      severity: 'critical',
      classification: 'mvp',
      detection: {
        signals: [{ type: 'directory', value: 'app/Services' }],
        confidence: 'high',
      },
      verification: {
        rules: ['controllers must not access Eloquent models directly'],
      },
      examples: {
        correct: 'this.orderService.create(dto)',
        violation: 'Order.create(request.all())',
      },
    };
    expect(pattern.name).toBe('service-layer');
    expect(pattern.detection.confidence).toBe('high');
  });

  it('should export FormatterContext with new scope values', () => {
    const ctx: FormatterContext = {
      violations: [],
      errors: [],
      baselineCount: 0,
      totalFiles: 5,
      scope: 'run',
    };
    expect(['hook', 'run', 'health']).toContain(ctx.scope);
  });

  it('should export ToolResult as discriminated union', () => {
    const success: ToolResult = { success: true, violations: [] };
    const failure: ToolResult = {
      success: false,
      error: { tool: 'larastan', reason: 'binary not found', fix: 'composer require larastan/larastan' },
    };
    expect(success.success).toBe(true);
    expect(failure.success).toBe(false);
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npm test`
Expected: FAIL — new types don't exist yet

- [ ] **Step 3: Update config.ts**

```typescript
// src/core/types/config.ts
export type Enforcement = 'block' | 'warn' | 'autofix';

export interface ToolConfig {
  enabled: boolean;
  binary: string;
  level?: number;
  rules?: Record<string, unknown>;
  enforcement: Enforcement;
  preset?: string;
  rulesets?: string[];
  config?: string;
  extensions?: string[];
  directory?: string;
}

export interface CapabilityConfig {
  enabled: boolean;
  enforcement: Enforcement;
  level?: number;
  presets?: string[];
}

export interface PatternsConfig {
  catalog: string[];
  discovered: string[];
  custom: string[];
}

export interface ThresholdsConfig {
  max_method_lines?: number;
  max_indentation_levels?: number;
}

export interface HookConfig {
  enabled: boolean;
  scope: 'staged-files';
}

export interface BaselineConfig {
  path: string;
  generated?: string;
}

export interface ProjectConfig {
  language: string;
  framework: string;
  module: string;
}

export interface CodeGuardConfig {
  version: string;
  project: ProjectConfig;
  capabilities: Record<string, CapabilityConfig>;
  patterns: PatternsConfig;
  thresholds?: ThresholdsConfig;  // optional — not all projects need custom thresholds
  hooks: Record<string, HookConfig>;
  baseline: BaselineConfig;
}
```

- [ ] **Step 4: Update violations.ts**

```typescript
// src/core/types/violations.ts
export type Severity = 'critical' | 'warning' | 'suggestion';

export interface AnalysisViolation {
  tool: string;
  rule: string;
  severity: Severity;
  file: string;
  line: number;
  column?: number;
  message: string;
  standard?: string;    // optional — populated by AI, not by hook runner
  reference?: string;   // optional — populated by AI, not by hook runner
  fixable?: boolean;
}

export interface ToolError {
  tool: string;
  reason: string;
  fix: string;
}

export type ToolResult =
  | { success: true; violations: AnalysisViolation[] }
  | { success: false; error: ToolError };

export interface AnalysisResult {
  violations: AnalysisViolation[];
  errors: ToolError[];
  timestamp: string;
}
```

- [ ] **Step 5: Update modules.ts**

**BREAKING CHANGE:** This step deliberately removes `CodeGuardModule` (replaced by `ModuleDefinition`) and `PresetConfig` (replaced by `PresetDefinition`). These were BMAD-era types that no longer match the new design. The old `ToolAdapter` (single `analyze()` method) is also replaced.

```typescript
// src/core/types/modules.ts
import type { ToolConfig } from './config.js';
import type { AnalysisViolation, ToolResult } from './violations.js';

export interface CommandSpec {
  binary: string;
  args: string[];
  cwd?: string;
  timeout?: number;
}

export interface DetectionSignal {
  type: 'directory' | 'file' | 'dependency' | 'import';
  value: string;
}

export interface PatternDetection {
  signals: DetectionSignal[];
  confidence: 'high' | 'medium' | 'low';
}

export interface PatternVerification {
  rules: string[];
}

export interface PatternExamples {
  correct: string;
  violation: string;
}

export interface PatternDefinition {
  name: string;
  description: string;
  category: 'architecture' | 'clean-code' | 'solid' | 'ddd' | 'php' | 'framework';
  layer: 'core' | 'php' | 'laravel';
  severity: 'critical' | 'warning' | 'suggestion';
  classification: 'mvp' | 'roadmap';
  detection: PatternDetection;
  verification: PatternVerification;
  examples: PatternExamples;
  related_patterns?: string[];
}

export interface ModuleCapability {
  tool: string;
  default_level?: number;
  preset?: string;
  rulesets?: string[];
  presets?: string[];
}

export interface ModuleDetection {
  files: string[];
  dependencies?: string[];
  confidence: 'high' | 'medium' | 'low';
}

export interface ModuleDefinition {
  name: string;
  label: string;
  language: string;
  framework: string;
  detection: ModuleDetection;
  capabilities: Record<string, ModuleCapability>;
}

export interface PresetTool {
  binary: string;
  config?: string;
  level?: number;
  preset?: string;
  rulesets?: string[];
  extensions?: string[];
  directory?: string;
}

export interface PresetDefinition {
  tools: Record<string, PresetTool>;
  install_commands: string[];
}

export interface DetectionResult {
  detected: boolean;
  module: string;
  confidence: 'high' | 'medium' | 'low';
  signals: string[];
}

export interface ToolAdapter {
  readonly name: string;
  readonly binary: string;
  buildCommand(files: string[], config: ToolConfig): CommandSpec;
  parseOutput(raw: string): ToolResult;
  filterToStaged(violations: AnalysisViolation[], stagedFiles: string[]): AnalysisViolation[];
}
```

- [ ] **Step 6: Update output.ts**

```typescript
// src/core/types/output.ts
import type { AnalysisViolation, ToolError } from './violations.js';

export interface FormatterContext {
  violations: AnalysisViolation[];
  errors: ToolError[];
  baselineCount: number;
  totalFiles: number;
  scope: 'hook' | 'run' | 'health';
}

export interface OutputFormatter {
  formatFindings(context: FormatterContext): string;
  formatError(error: ToolError): string;
  formatSummary(context: FormatterContext): string;
}
```

- [ ] **Step 7: Update index.ts barrel and main entry**

```typescript
// src/core/types/index.ts
export type {
  Severity,
  AnalysisViolation,
  ToolError,
  ToolResult,
  AnalysisResult,
} from './violations.js';

export type {
  Enforcement,
  ToolConfig,
  CapabilityConfig,
  PatternsConfig,
  ThresholdsConfig,
  HookConfig,
  BaselineConfig,
  ProjectConfig,
  CodeGuardConfig,
} from './config.js';

export type {
  CommandSpec,
  DetectionSignal,
  PatternDetection,
  PatternVerification,
  PatternExamples,
  PatternDefinition,
  ModuleCapability,
  ModuleDetection,
  ModuleDefinition,
  PresetTool,
  PresetDefinition,
  DetectionResult,
  ToolAdapter,
} from './modules.js';

export type {
  FormatterContext,
  OutputFormatter,
} from './output.js';
```

Replace `src/index.ts` with:

```typescript
// src/index.ts — Main entry point, re-exports core types for public API
export type {
  Severity,
  AnalysisViolation,
  ToolError,
  ToolResult,
  AnalysisResult,
  Enforcement,
  ToolConfig,
  CapabilityConfig,
  PatternsConfig,
  ThresholdsConfig,
  HookConfig,
  BaselineConfig,
  ProjectConfig,
  CodeGuardConfig,
  CommandSpec,
  DetectionSignal,
  PatternDetection,
  PatternVerification,
  PatternExamples,
  PatternDefinition,
  ModuleCapability,
  ModuleDetection,
  ModuleDefinition,
  PresetTool,
  PresetDefinition,
  DetectionResult,
  ToolAdapter,
  FormatterContext,
  OutputFormatter,
} from './core/types/index.js';
```

NOTE: `CodeGuardModule` and `PresetConfig` are deliberately removed — replaced by `ModuleDefinition` and `PresetDefinition`.

- [ ] **Step 8: Run tests to verify they pass**

Run: `npm test`
Expected: PASS — all 7 tests

- [ ] **Step 9: Run build and lint**

Run: `npm run build && npm run lint`
Expected: Both pass with zero errors

- [ ] **Step 10: Commit**

```bash
git add src/core/types/ src/index.ts tests/unit/core/types.test.ts
git commit -m "feat: update type contracts for new design (capabilities, patterns, ToolAdapter)"
```

---

## Task 2: Remove Unused Dependencies

**Files:**
- Modify: `package.json`

- [ ] **Step 1: Remove handlebars and cosmiconfig**

Run: `npm uninstall handlebars cosmiconfig`

- [ ] **Step 2: Verify build still works**

Run: `npm run build && npm run lint && npm test`
Expected: All pass (these packages were never imported in code)

- [ ] **Step 3: Commit**

```bash
git add package.json package-lock.json
git commit -m "chore: remove handlebars and cosmiconfig (no longer needed)"
```

---

## Task 3: Config Loader

**Files:**
- Create: `src/core/config/loader.ts`
- Create: `src/core/config/schema.ts`
- Create: `src/core/config/index.ts`
- Create: `tests/unit/core/config/loader.test.ts`
- Create: `tests/fixtures/codeguard.yaml`
- Create: `tests/fixtures/codeguard-invalid.yaml`
- Test: `tests/unit/core/config/loader.test.ts`

- [ ] **Step 1: Create test fixtures**

```yaml
# tests/fixtures/codeguard.yaml
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
patterns:
  catalog:
    - service-layer
    - dto
  discovered: []
  custom: []
thresholds:
  max_method_lines: 20
  max_indentation_levels: 2
hooks:
  pre-commit:
    enabled: true
    scope: staged-files
baseline:
  path: .codeguard/baseline.json
```

```yaml
# tests/fixtures/codeguard-invalid.yaml
version: "1.0"
project:
  language: php
# missing required fields: framework, module, capabilities, patterns
```

- [ ] **Step 2: Write failing tests**

```typescript
// tests/unit/core/config/loader.test.ts
import { describe, it, expect } from 'vitest';
import { join } from 'node:path';

import { loadConfig } from '../../../../src/core/config/loader.js';

const fixturesDir = join(import.meta.dirname, '../../../fixtures');

describe('Config Loader', () => {
  it('should load a valid codeguard.yaml', async () => {
    const result = await loadConfig(join(fixturesDir, 'codeguard.yaml'));
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.version).toBe('1.0');
      expect(result.data.project.framework).toBe('laravel');
      expect(result.data.capabilities['static-analysis'].level).toBe(6);
      expect(result.data.patterns.catalog).toContain('service-layer');
    }
  });

  it('should return error for invalid config', async () => {
    const result = await loadConfig(join(fixturesDir, 'codeguard-invalid.yaml'));
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error).toBeTruthy();
    }
  });

  it('should return error for nonexistent file', async () => {
    const result = await loadConfig('/nonexistent/codeguard.yaml');
    expect(result.success).toBe(false);
  });
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `npm test`
Expected: FAIL — loadConfig doesn't exist

- [ ] **Step 4: Implement config schema**

NOTE: Do NOT use ajv's `JSONSchemaType<T>` generic — it has known issues with `Record<string, T>` and optional properties. Use a plain object schema instead. Runtime validation works identically.

```typescript
// src/core/config/schema.ts

// Plain JSON Schema object — not typed with JSONSchemaType<T> due to ajv limitations
// with Record types and optional properties. Runtime validation is identical.
export const configSchema = {
  type: 'object',
  required: ['version', 'project', 'capabilities', 'patterns', 'hooks', 'baseline'],
  additionalProperties: false,
  properties: {
    version: { type: 'string' },
    project: {
      type: 'object',
      required: ['language', 'framework', 'module'],
      properties: {
        language: { type: 'string' },
        framework: { type: 'string' },
        module: { type: 'string' },
      },
    },
    capabilities: {
      type: 'object',
      additionalProperties: {
        type: 'object',
        required: ['enabled', 'enforcement'],
        properties: {
          enabled: { type: 'boolean' },
          enforcement: { type: 'string', enum: ['block', 'warn', 'autofix'] },
          level: { type: 'number' },
          presets: { type: 'array', items: { type: 'string' } },
          autofix: { type: 'boolean' },
        },
      },
    },
    patterns: {
      type: 'object',
      required: ['catalog', 'discovered', 'custom'],
      properties: {
        catalog: { type: 'array', items: { type: 'string' } },
        discovered: { type: 'array', items: { type: 'string' } },
        custom: { type: 'array', items: { type: 'string' } },
      },
    },
    thresholds: {
      type: 'object',
      properties: {
        max_method_lines: { type: 'number' },
        max_indentation_levels: { type: 'number' },
      },
    },
    hooks: {
      type: 'object',
      additionalProperties: {
        type: 'object',
        required: ['enabled', 'scope'],
        properties: {
          enabled: { type: 'boolean' },
          scope: { type: 'string' },
        },
      },
    },
    baseline: {
      type: 'object',
      required: ['path'],
      properties: {
        path: { type: 'string' },
        generated: { type: 'string' },
      },
    },
  },
} as const;
```

- [ ] **Step 5: Implement config loader**

NOTE: If the `import Ajv from 'ajv'` default import causes issues with `verbatimModuleSyntax`, try `import { default as Ajv } from 'ajv'` or check tsdown handles it.

```typescript
// src/core/config/loader.ts
import { readFile } from 'node:fs/promises';
import { parse } from 'yaml';
import Ajv from 'ajv';

import type { CodeGuardConfig } from '../types/index.js';
import { configSchema } from './schema.js';

// Generic Result type — reuse across loaders
export type Result<T> =
  | { success: true; data: T }
  | { success: false; error: string };

export async function loadConfig(filePath: string): Promise<Result<CodeGuardConfig>> {
  let raw: string;
  try {
    raw = await readFile(filePath, { encoding: 'utf-8' });
  } catch {
    return { success: false, error: `Config file not found: ${filePath}` };
  }

  let parsed: unknown;
  try {
    parsed = parse(raw);
  } catch {
    return { success: false, error: `Invalid YAML in ${filePath}` };
  }

  const ajv = new Ajv({ allErrors: true });
  const validate = ajv.compile(configSchema);

  if (!validate(parsed)) {
    const errors = validate.errors?.map((e) => `${e.instancePath} ${e.message}`).join('; ');
    return { success: false, error: `Config validation failed: ${errors}` };
  }

  return { success: true, data: parsed };
}
```

```typescript
// src/core/config/index.ts
export { loadConfig } from './loader.js';
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `npm test`
Expected: PASS

- [ ] **Step 7: Run build and lint**

Run: `npm run build && npm run lint`
Expected: Both pass

- [ ] **Step 8: Commit**

```bash
git add src/core/config/ tests/unit/core/config/ tests/fixtures/
git commit -m "feat: add config loader with YAML parsing and ajv validation"
```

---

## Task 4: Pattern Loader

**Files:**
- Create: `src/core/patterns/loader.ts`
- Create: `src/core/patterns/index.ts`
- Create: `modules/core/patterns/single-responsibility.yaml`
- Create: `modules/php-laravel/patterns/service-layer.yaml`
- Create: `tests/unit/core/patterns/loader.test.ts`
- Test: `tests/unit/core/patterns/loader.test.ts`

- [ ] **Step 1: Create pattern fixture YAMLs**

NOTE: The detection signal format uses structured `{type, value}` objects instead of the spec's shorthand (`directory: app/Services`). This is an intentional refinement — structured objects are easier to parse programmatically.

```yaml
# modules/core/patterns/single-responsibility.yaml
name: single-responsibility
description: A class or function should have one, and only one, reason to change
category: solid
layer: core
severity: warning
classification: mvp

detection:
  signals:
    - type: file
      value: "**/*.php"
  confidence: medium

verification:
  rules:
    - classes should not have more than one clearly distinct responsibility
    - methods should not mix I/O with business logic

examples:
  correct: |
    class OrderService {
      public function create(OrderData $data): Order { /* order logic only */ }
    }
  violation: |
    class OrderService {
      public function create(array $data): Order { /* creates order AND sends email AND logs */ }
    }
```

```yaml
# modules/php-laravel/patterns/service-layer.yaml
name: service-layer
description: Controllers delegate business logic to Services
category: architecture
layer: laravel
severity: critical
classification: mvp

detection:
  signals:
    - type: directory
      value: app/Services
    - type: import
      value: "App\\Services\\*"
  confidence: high

verification:
  rules:
    - controllers must not access Eloquent models directly
    - controllers must not contain business logic
    - services must not return HTTP responses
    - services must not access Request object

examples:
  correct: |
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $result = $this->orderService->create(OrderData::from($request));
        return response()->json($result);
    }
  violation: |
    public function store(Request $request): JsonResponse
    {
        $order = Order::create($request->all());
        return response()->json($order);
    }

related_patterns:
  - dto
  - form-requests
```

- [ ] **Step 2: Write failing tests**

```typescript
// tests/unit/core/patterns/loader.test.ts
import { describe, it, expect } from 'vitest';
import { join } from 'node:path';

import { loadPatterns, loadPattern } from '../../../../src/core/patterns/loader.js';

const modulesDir = join(import.meta.dirname, '../../../../modules');

describe('Pattern Loader', () => {
  it('should load a single pattern YAML', async () => {
    const result = await loadPattern(
      join(modulesDir, 'core/patterns/single-responsibility.yaml'),
    );
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.name).toBe('single-responsibility');
      expect(result.data.category).toBe('solid');
      expect(result.data.layer).toBe('core');
      expect(result.data.verification.rules.length).toBeGreaterThan(0);
    }
  });

  it('should load all patterns from a directory', async () => {
    const result = await loadPatterns(join(modulesDir, 'core/patterns'));
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.length).toBeGreaterThan(0);
      expect(result.data[0].name).toBe('single-responsibility');
    }
  });

  it('should load Laravel patterns', async () => {
    const result = await loadPatterns(join(modulesDir, 'php-laravel/patterns'));
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.some((p) => p.name === 'service-layer')).toBe(true);
    }
  });

  it('should return error for nonexistent directory', async () => {
    const result = await loadPatterns('/nonexistent/patterns');
    expect(result.success).toBe(false);
  });
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `npm test`
Expected: FAIL — loadPatterns doesn't exist

- [ ] **Step 4: Implement pattern loader**

```typescript
// src/core/patterns/loader.ts
import { readFile, readdir } from 'node:fs/promises';
import { join } from 'node:path';
import { parse } from 'yaml';

import type { PatternDefinition } from '../types/index.js';

type PatternResult =
  | { success: true; data: PatternDefinition }
  | { success: false; error: string };

type PatternsResult =
  | { success: true; data: PatternDefinition[]; warnings: string[] }
  | { success: false; error: string };

export async function loadPattern(filePath: string): Promise<PatternResult> {
  let raw: string;
  try {
    raw = await readFile(filePath, { encoding: 'utf-8' });
  } catch {
    return { success: false, error: `Pattern file not found: ${filePath}` };
  }

  try {
    const parsed = parse(raw) as PatternDefinition;
    if (!parsed.name || !parsed.verification?.rules) {
      return { success: false, error: `Invalid pattern: missing required fields in ${filePath}` };
    }
    return { success: true, data: parsed };
  } catch {
    return { success: false, error: `Invalid YAML in ${filePath}` };
  }
}

export async function loadPatterns(directoryPath: string): Promise<PatternsResult> {
  let files: string[];
  try {
    files = await readdir(directoryPath);
  } catch {
    return { success: false, error: `Patterns directory not found: ${directoryPath}` };
  }

  const yamlFiles = files.filter((f) => f.endsWith('.yaml') || f.endsWith('.yml'));
  const patterns: PatternDefinition[] = [];
  const warnings: string[] = [];

  for (const file of yamlFiles.sort()) {
    const result = await loadPattern(join(directoryPath, file));
    if (result.success) {
      patterns.push(result.data);
    } else {
      warnings.push(result.error);
    }
  }

  return { success: true, data: patterns, warnings };
}
```

```typescript
// src/core/patterns/index.ts
export { loadPattern, loadPatterns } from './loader.js';
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `npm test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/core/patterns/ modules/ tests/unit/core/patterns/
git commit -m "feat: add pattern loader for YAML pattern catalog"
```

---

## Task 5: Module Loader & Registry

**Files:**
- Create: `src/core/modules/loader.ts`
- Create: `src/core/modules/registry.ts`
- Create: `src/core/modules/index.ts`
- Create: `modules/php-laravel/module.yaml`
- Create: `modules/php-laravel/preset.yaml`
- Create: `tests/unit/core/modules/loader.test.ts`
- Create: `tests/unit/core/modules/registry.test.ts`
- Test: `tests/unit/core/modules/loader.test.ts`
- Test: `tests/unit/core/modules/registry.test.ts`

- [ ] **Step 1: Create module fixture YAMLs**

```yaml
# modules/php-laravel/module.yaml
name: php-laravel
label: Laravel
language: php
framework: laravel

detection:
  files:
    - composer.json
    - artisan
  dependencies:
    - laravel/framework
  confidence: high

capabilities:
  static-analysis:
    tool: larastan
    default_level: 6
  formatting:
    tool: pint
    preset: laravel
  mess-detection:
    tool: phpmd
    rulesets: [unusedcode, codesize]
  arch-testing:
    tool: pest
    presets: [php, laravel]
```

```yaml
# modules/php-laravel/preset.yaml
tools:
  larastan:
    binary: vendor/bin/phpstan
    config: phpstan.neon
    level: 6
    extensions: [larastan]
  pint:
    binary: vendor/bin/pint
    config: pint.json
    preset: laravel
  phpmd:
    binary: vendor/bin/phpmd
    config: phpmd.xml
    rulesets: [unusedcode, codesize]
  pest:
    binary: vendor/bin/pest
    directory: tests/Architecture

install_commands:
  - composer require --dev larastan/larastan
  - composer require --dev laravel/pint
  - composer require --dev phpmd/phpmd
  - composer require --dev pestphp/pest
```

- [ ] **Step 2: Write failing tests for module loader**

```typescript
// tests/unit/core/modules/loader.test.ts
import { describe, it, expect } from 'vitest';
import { join } from 'node:path';

import { loadModuleDefinition, loadPresetDefinition } from '../../../../src/core/modules/loader.js';

const modulesDir = join(import.meta.dirname, '../../../../modules');

describe('Module Loader', () => {
  it('should load module.yaml', async () => {
    const result = await loadModuleDefinition(join(modulesDir, 'php-laravel/module.yaml'));
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.name).toBe('php-laravel');
      expect(result.data.language).toBe('php');
      expect(result.data.capabilities['static-analysis'].tool).toBe('larastan');
    }
  });

  it('should load preset.yaml', async () => {
    const result = await loadPresetDefinition(join(modulesDir, 'php-laravel/preset.yaml'));
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.tools.larastan.binary).toBe('vendor/bin/phpstan');
      expect(result.data.install_commands.length).toBeGreaterThan(0);
    }
  });
});
```

- [ ] **Step 3: Write failing tests for module registry**

```typescript
// tests/unit/core/modules/registry.test.ts
import { describe, it, expect } from 'vitest';
import { join } from 'node:path';

import { discoverModules, findModuleForProject } from '../../../../src/core/modules/registry.js';

const modulesDir = join(import.meta.dirname, '../../../../modules');

describe('Module Registry', () => {
  it('should discover available modules', async () => {
    const modules = await discoverModules(modulesDir);
    expect(modules.length).toBeGreaterThan(0);
    expect(modules.some((m) => m.name === 'php-laravel')).toBe(true);
  });

  it('should find module matching project signals', async () => {
    const modules = await discoverModules(modulesDir);
    const result = findModuleForProject(modules, {
      files: ['composer.json', 'artisan'],
      dependencies: ['laravel/framework'],
    });
    expect(result).toBeTruthy();
    expect(result?.name).toBe('php-laravel');
  });

  it('should return undefined when no module matches', async () => {
    const modules = await discoverModules(modulesDir);
    const result = findModuleForProject(modules, {
      files: ['package.json'],
      dependencies: ['react'],
    });
    expect(result).toBeUndefined();
  });
});
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `npm test`
Expected: FAIL

- [ ] **Step 5: Implement module loader**

Implement `loadModuleDefinition` and `loadPresetDefinition` following the same pattern as config/pattern loaders (read YAML, parse, validate minimally, return Result type).

- [ ] **Step 6: Implement module registry**

```typescript
// src/core/modules/registry.ts
import { readdir } from 'node:fs/promises';
import { join } from 'node:path';

import type { ModuleDefinition } from '../types/index.js';
import { loadModuleDefinition } from './loader.js';

export interface ProjectSignals {
  files: string[];
  dependencies: string[];
}

export async function discoverModules(modulesDir: string): Promise<ModuleDefinition[]> {
  let entries: string[];
  try {
    entries = await readdir(modulesDir, { withFileTypes: true })
      .then((dirents) => dirents.filter((d) => d.isDirectory()).map((d) => d.name));
  } catch {
    return [];
  }

  const modules: ModuleDefinition[] = [];
  for (const entry of entries) {
    const moduleYaml = join(modulesDir, entry, 'module.yaml');
    const result = await loadModuleDefinition(moduleYaml);
    if (result.success) {
      modules.push(result.data);
    }
  }
  return modules;
}

// Matching logic: ALL required files must be present AND at least one dependency must match.
// core/ and php/ are special — they don't have detection (always loaded based on language).
export function findModuleForProject(
  modules: ModuleDefinition[],
  signals: ProjectSignals,
): ModuleDefinition | undefined {
  return modules.find((mod) => {
    const requiredFiles = mod.detection.files;
    const allFilesPresent = requiredFiles.every((f) => signals.files.includes(f));
    if (!allFilesPresent) return false;

    const requiredDeps = mod.detection.dependencies ?? [];
    if (requiredDeps.length === 0) return allFilesPresent;

    return requiredDeps.some((dep) => signals.dependencies.includes(dep));
  });
}
```

NOTE: A higher-level `resolveModule()` function that composes module loader + pattern loader + preset loader will be built in a later phase. Phase 1 focuses on the individual loaders.

- [ ] **Step 7: Run tests to verify they pass**

Run: `npm test`
Expected: PASS

- [ ] **Step 8: Run build and lint**

Run: `npm run build && npm run lint`
Expected: Both pass

- [ ] **Step 9: Commit**

```bash
git add src/core/modules/ modules/php-laravel/ tests/unit/core/modules/
git commit -m "feat: add module loader and registry with Laravel module definition"
```

---

## Task 6: Update Build Config & Final Verification

**Files:**
- Modify: `tsdown.config.ts`
- Modify: `package.json` (`files` field to include `modules/`)

- [ ] **Step 1: Update tsdown config to include new entry points if needed**

Review if the config loader and pattern loader should be separate entry points or part of the main bundle. For Phase 1, they are internal — no new entries needed.

- [ ] **Step 2: Add `modules/` to package.json `files` field**

```json
"files": [
  "dist/",
  "bin/",
  "skills/",
  "modules/"
]
```

- [ ] **Step 3: Full verification**

Run: `npm run build && npm run lint && npm test`
Expected: All pass. All new types, loaders, and tests working.

- [ ] **Step 4: Commit**

```bash
git add tsdown.config.ts package.json
git commit -m "chore: include modules/ in package files"
```

---

## Phase 1 Summary

After completing all 6 tasks, the project has:

- Updated type contracts matching the new design (capabilities, patterns, ToolAdapter, CommandSpec)
- Config loader (codeguard.yaml → validated CodeGuardConfig)
- Pattern loader (YAML files → PatternDefinition[])
- Module loader (module.yaml + preset.yaml → ModuleDefinition + PresetDefinition)
- Module registry (discover modules, match by project signals)
- 2 example pattern files (core: SRP, laravel: Service Layer)
- Laravel module definition (module.yaml + preset.yaml)
- Full test coverage for all new code

**Next:** Phases 2-4 can start in parallel (installer, pattern content, tool adapters).
