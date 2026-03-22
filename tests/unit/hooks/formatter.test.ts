import { describe, it, expect } from 'vitest';

import type {
  FormatterContext,
  AnalysisViolation,
  ToolError,
} from '../../../src/core/types/index.js';
import { hookFormatter } from '../../../src/hooks/formatter.js';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeContext(overrides: Partial<FormatterContext> = {}): FormatterContext {
  return {
    violations: [],
    errors: [],
    baselineCount: 0,
    totalFiles: 5,
    scope: 'hook',
    ...overrides,
  };
}

function makeViolation(overrides: Partial<AnalysisViolation> = {}): AnalysisViolation {
  return {
    tool: 'larastan',
    rule: 'missingType.return',
    severity: 'critical',
    file: 'app/Models/User.php',
    line: 42,
    message: 'Method getFullName() has no return type specified.',
    ...overrides,
  };
}

function makeError(overrides: Partial<ToolError> = {}): ToolError {
  return {
    tool: 'phpmd',
    reason: 'Binary not found: vendor/bin/phpmd',
    fix: 'Install with: composer require --dev phpmd/phpmd',
    ...overrides,
  };
}

// Strip ANSI escape codes for easier assertion
function stripAnsi(str: string): string {
  // eslint-disable-next-line no-control-regex
  return str.replace(/\u001b\[[0-9;]*m/g, '');
}

// ---------------------------------------------------------------------------
// formatSummary
// ---------------------------------------------------------------------------

describe('hookFormatter.formatSummary', () => {
  it('shows "All checks passed" when there are 0 violations and 0 errors', () => {
    const ctx = makeContext();
    const result = stripAnsi(hookFormatter.formatSummary(ctx));
    expect(result).toContain('All checks passed');
  });

  it('shows "commit blocked" when there are blocking (critical) violations', () => {
    const ctx = makeContext({
      violations: [makeViolation({ severity: 'critical' })],
    });
    const result = stripAnsi(hookFormatter.formatSummary(ctx));
    expect(result).toContain('commit blocked');
  });

  it('shows "commit passed" when there are only warnings', () => {
    const ctx = makeContext({
      violations: [makeViolation({ severity: 'warning' })],
    });
    const result = stripAnsi(hookFormatter.formatSummary(ctx));
    expect(result).toContain('commit passed');
    expect(result).not.toContain('commit blocked');
  });

  it('includes baselined message when baselineCount > 0', () => {
    const ctx = makeContext({ baselineCount: 3 });
    const result = stripAnsi(hookFormatter.formatSummary(ctx));
    expect(result).toContain('3 baselined');
    expect(result).toContain('suppressed');
  });

  it('does not include baselined message when baselineCount is 0', () => {
    const ctx = makeContext({ baselineCount: 0 });
    const result = stripAnsi(hookFormatter.formatSummary(ctx));
    expect(result).not.toContain('baselined');
  });
});

// ---------------------------------------------------------------------------
// formatFindings
// ---------------------------------------------------------------------------

describe('hookFormatter.formatFindings', () => {
  it('includes tool name and file:line for each violation', () => {
    const v = makeViolation({
      tool: 'larastan',
      file: 'app/Models/User.php',
      line: 42,
      message: 'Missing return type',
    });
    const ctx = makeContext({ violations: [v] });
    const result = stripAnsi(hookFormatter.formatFindings(ctx));

    expect(result).toContain('app/Models/User.php:42');
    expect(result).toContain('Larastan');
    expect(result).toContain('Missing return type');
  });

  it('includes error information when tools fail', () => {
    const err = makeError({
      tool: 'phpmd',
      reason: 'Binary not found',
    });
    const ctx = makeContext({ errors: [err] });
    const result = stripAnsi(hookFormatter.formatFindings(ctx));

    expect(result).toContain('phpmd');
    expect(result).toContain('Binary not found');
  });
});

// ---------------------------------------------------------------------------
// formatError
// ---------------------------------------------------------------------------

describe('hookFormatter.formatError', () => {
  it('includes tool name and reason', () => {
    const err = makeError({
      tool: 'pest',
      reason: 'Timeout after 60s',
    });
    const result = stripAnsi(hookFormatter.formatError(err));

    expect(result).toContain('pest');
    expect(result).toContain('Timeout after 60s');
  });
});
