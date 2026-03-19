import { describe, it, expect } from 'vitest';

import type {
  Severity,
  AnalysisViolation,
  ToolError,
  ToolResult,
  AnalysisResult,
  ToolConfig,
  CapabilityConfig,
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

  it('should allow CodeGuardConfig without thresholds', () => {
    const config: CodeGuardConfig = {
      version: '1.0',
      project: { language: 'php', framework: 'laravel', module: 'php-laravel' },
      capabilities: {},
      patterns: { catalog: [], discovered: [], custom: [] },
      hooks: { 'pre-commit': { enabled: true, scope: 'staged-files' } },
      baseline: { path: '.codeguard/baseline.json' },
    };
    expect(config.thresholds).toBeUndefined();
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
