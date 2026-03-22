import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, it, expect } from 'vitest';

import { pestAdapter } from '../../../src/adapters/php-laravel/pest.js';
import type { ToolConfig, AnalysisViolation } from '../../../src/core/types/index.js';

const defaultConfig: ToolConfig = {
  enabled: true,
  binary: 'vendor/bin/pest',
  enforcement: 'block',
};

describe('pestAdapter', () => {
  describe('buildCommand', () => {
    it('produces correct args with default directory', () => {
      const cmd = pestAdapter.buildCommand([], defaultConfig);
      expect(cmd.binary).toBe('vendor/bin/pest');
      expect(cmd.args).toEqual(['tests/Architecture', '--colors=never']);
      expect(cmd.timeout).toBe(120_000);
    });

    it('uses custom directory from config', () => {
      const config: ToolConfig = { ...defaultConfig, directory: 'tests/Arch' };
      const cmd = pestAdapter.buildCommand([], config);
      expect(cmd.args[0]).toBe('tests/Arch');
      expect(cmd.args[1]).toBe('--colors=never');
    });
  });

  describe('parseOutput', () => {
    it('parses fixture text into 2 violations (only FAIL lines)', () => {
      const fixture = readFileSync(
        resolve(import.meta.dirname, '../../fixtures/pest-output.txt'),
        'utf-8',
      );
      const result = pestAdapter.parseOutput(fixture);

      expect(result.success).toBe(true);
      if (!result.success) return;

      expect(result.violations).toHaveLength(2);

      // First FAIL: controllers should not depend on models
      expect(result.violations[0]).toMatchObject({
        tool: 'pest',
        rule: 'arch.arch:-controllers-should-not-depend-on-models',
        severity: 'critical',
        file: 'tests/Architecture/CodeGuardArchTest.php',
        line: 15,
        message: 'Arch test failed: arch: controllers should not depend on models',
        fixable: false,
      });

      // Second FAIL: services should not return http responses
      expect(result.violations[1]).toMatchObject({
        tool: 'pest',
        rule: 'arch.arch:-services-should-not-return-http-responses',
        severity: 'critical',
        file: 'app/Services/OrderService.php',
        line: 23,
        message: 'Arch test failed: arch: services should not return http responses',
        fixable: false,
      });
    });

    it('returns success: true with empty violations for empty output', () => {
      const result = pestAdapter.parseOutput('');

      expect(result.success).toBe(true);
      if (!result.success) return;

      expect(result.violations).toEqual([]);
    });
  });

  describe('filterToStaged', () => {
    it('returns all violations (project-wide arch tests)', () => {
      const violations: AnalysisViolation[] = [
        {
          tool: 'pest',
          rule: 'arch.controllers-should-not-depend-on-models',
          severity: 'critical',
          file: 'tests/Architecture/CodeGuardArchTest.php',
          line: 15,
          message: 'Arch test failed',
        },
        {
          tool: 'pest',
          rule: 'arch.services-should-not-return-http-responses',
          severity: 'critical',
          file: 'app/Services/OrderService.php',
          line: 23,
          message: 'Arch test failed',
        },
      ];

      const staged = ['app/Http/Controllers/OrderController.php'];
      const filtered = pestAdapter.filterToStaged(violations, staged);

      // All violations returned regardless of staged files
      expect(filtered).toBe(violations);
      expect(filtered).toHaveLength(2);
    });
  });
});
