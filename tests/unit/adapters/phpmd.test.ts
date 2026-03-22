import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, it, expect } from 'vitest';

import { phpmdAdapter } from '../../../src/adapters/php-laravel/phpmd.js';
import type { ToolConfig, AnalysisViolation } from '../../../src/core/types/index.js';

const defaultConfig: ToolConfig = {
  enabled: true,
  binary: 'vendor/bin/phpmd',
  enforcement: 'warn',
};

describe('phpmdAdapter', () => {
  describe('buildCommand', () => {
    it('produces correct args with comma-separated files and default rulesets', () => {
      const files = ['app/Models/User.php', 'app/Services/OrderService.php'];
      const cmd = phpmdAdapter.buildCommand(files, defaultConfig);

      expect(cmd.binary).toBe('vendor/bin/phpmd');
      expect(cmd.args).toEqual([
        'app/Models/User.php,app/Services/OrderService.php',
        'json',
        'codesize,design,unusedcode',
      ]);
      expect(cmd.timeout).toBe(60_000);
    });

    it('joins custom rulesets from config', () => {
      const config: ToolConfig = {
        ...defaultConfig,
        rulesets: ['cleancode', 'naming', 'design'],
      };
      const cmd = phpmdAdapter.buildCommand(['file.php'], config);
      expect(cmd.args[2]).toBe('cleancode,naming,design');
    });
  });

  describe('parseOutput', () => {
    it('parses valid JSON fixture into 2 violations', () => {
      const fixture = readFileSync(
        resolve(import.meta.dirname, '../../fixtures/phpmd-output.json'),
        'utf-8',
      );
      const result = phpmdAdapter.parseOutput(fixture);

      expect(result.success).toBe(true);
      if (!result.success) return;

      expect(result.violations).toHaveLength(2);

      // First violation: CyclomaticComplexity
      expect(result.violations[0]).toMatchObject({
        tool: 'phpmd',
        rule: 'codesize.CyclomaticComplexity',
        severity: 'warning',
        file: 'app/Http/Controllers/OrderController.php',
        line: 23,
        message: 'The method processOrder() has a Cyclomatic Complexity of 15. The configured maximum is 10.',
        fixable: false,
      });

      // Second violation: UnusedLocalVariable
      expect(result.violations[1]).toMatchObject({
        tool: 'phpmd',
        rule: 'unusedcode.UnusedLocalVariable',
        severity: 'warning',
        file: 'app/Http/Controllers/OrderController.php',
        line: 50,
        message: "Avoid unused local variables such as '$temp'.",
        fixable: false,
      });
    });

    it('returns success: false for invalid JSON', () => {
      const result = phpmdAdapter.parseOutput('not valid json {{');

      expect(result.success).toBe(false);
      if (result.success) return;

      expect(result.error.tool).toBe('phpmd');
      expect(result.error.reason).toContain('Failed to parse');
    });
  });

  describe('filterToStaged', () => {
    it('filters violations to only staged files', () => {
      const violations: AnalysisViolation[] = [
        {
          tool: 'phpmd',
          rule: 'codesize.CyclomaticComplexity',
          severity: 'warning',
          file: 'app/Http/Controllers/OrderController.php',
          line: 23,
          message: 'complexity issue',
        },
        {
          tool: 'phpmd',
          rule: 'unusedcode.UnusedLocalVariable',
          severity: 'warning',
          file: 'app/Services/PaymentService.php',
          line: 50,
          message: 'unused variable',
        },
      ];

      const staged = ['app/Http/Controllers/OrderController.php'];
      const filtered = phpmdAdapter.filterToStaged(violations, staged);

      expect(filtered).toHaveLength(1);
      expect(filtered[0].file).toBe('app/Http/Controllers/OrderController.php');
    });
  });
});
