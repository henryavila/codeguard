import { describe, it, expect } from 'vitest';

// Import all types to verify they are importable from barrel
import type {
  Severity,
  AnalysisViolation,
  ToolError,
  ToolResult,
  AnalysisResult,
  ToolConfig,
  PresetConfig,
  HookConfig,
  BaselineConfig,
  CodeGuardConfig,
  DetectionResult,
  PatternDefinition,
  CodeGuardModule,
  ToolAdapter,
  FormatterContext,
  OutputFormatter,
} from '../../../src/core/types/index.js';

describe('Core Types', () => {
  it('should export Severity type with correct literal values', () => {
    const severity: Severity = 'critical';
    expect(['critical', 'warning', 'suggestion']).toContain(severity);
  });

  it('should export AnalysisViolation with required fields', () => {
    const violation: AnalysisViolation = {
      tool: 'phpstan',
      rule: 'missingType',
      severity: 'warning',
      file: 'src/Foo.php',
      line: 10,
      message: 'Missing return type',
      standard: 'Type Safety',
      reference: 'Standards > Type Safety',
    };
    expect(violation.tool).toBe('phpstan');
  });

  it('should export ToolResult as discriminated union', () => {
    const success: ToolResult = { success: true, violations: [] };
    const failure: ToolResult = {
      success: false,
      error: { tool: 'phpstan', reason: 'binary not found', fix: 'composer require phpstan/phpstan' },
    };
    expect(success.success).toBe(true);
    expect(failure.success).toBe(false);
  });

  it('should export AnalysisResult as pipeline aggregate', () => {
    const result: AnalysisResult = {
      violations: [],
      errors: [],
      timestamp: new Date().toISOString(),
    };
    expect(result.violations).toEqual([]);
    expect(result.errors).toEqual([]);
    expect(result.timestamp).toBeTruthy();
  });
});
