import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, it, expect } from 'vitest';

import { larastanAdapter } from '../../../src/adapters/php-laravel/larastan.js';
import type { ToolConfig, AnalysisViolation } from '../../../src/core/types/index.js';

const defaultConfig: ToolConfig = {
  enabled: true,
  binary: 'vendor/bin/phpstan',
  enforcement: 'block',
};

describe('larastanAdapter', () => {
  describe('buildCommand', () => {
    it('produces correct args with defaults', () => {
      const cmd = larastanAdapter.buildCommand([], defaultConfig);
      expect(cmd.binary).toBe('vendor/bin/phpstan');
      expect(cmd.args).toEqual(['analyse', '--error-format=json', '--no-progress']);
      expect(cmd.timeout).toBe(120_000);
    });

    it('includes --level when config.level is set', () => {
      const config: ToolConfig = { ...defaultConfig, level: 6 };
      const cmd = larastanAdapter.buildCommand([], config);
      expect(cmd.args).toContain('--level=6');
    });

    it('includes --configuration when config.config is set', () => {
      const config: ToolConfig = { ...defaultConfig, config: 'phpstan.neon' };
      const cmd = larastanAdapter.buildCommand([], config);
      expect(cmd.args).toContain('--configuration=phpstan.neon');
    });
  });

  describe('parseOutput', () => {
    it('parses valid JSON fixture into 3 violations', () => {
      const fixture = readFileSync(
        resolve(import.meta.dirname, '../../fixtures/larastan-output.json'),
        'utf-8',
      );
      const result = larastanAdapter.parseOutput(fixture);

      expect(result.success).toBe(true);
      if (!result.success) return;

      expect(result.violations).toHaveLength(3);

      // First violation: OrderService method.notFound
      expect(result.violations[0]).toMatchObject({
        tool: 'larastan',
        rule: 'method.notFound',
        severity: 'critical',
        file: 'app/Services/OrderService.php',
        line: 45,
        message: 'Call to undefined method App\\Models\\Order::calculateTotal().',
        fixable: false,
      });

      // Second violation: OrderService argument.type
      expect(result.violations[1]).toMatchObject({
        tool: 'larastan',
        rule: 'argument.type',
        severity: 'critical',
        file: 'app/Services/OrderService.php',
        line: 67,
      });

      // Third violation: OrderController property.notFound
      expect(result.violations[2]).toMatchObject({
        tool: 'larastan',
        rule: 'property.notFound',
        severity: 'critical',
        file: 'app/Http/Controllers/OrderController.php',
        line: 23,
      });
    });

    it('returns success: false with error from error fixture', () => {
      const fixture = readFileSync(
        resolve(import.meta.dirname, '../../fixtures/larastan-output-error.json'),
        'utf-8',
      );
      const result = larastanAdapter.parseOutput(fixture);

      expect(result.success).toBe(false);
      if (result.success) return;

      expect(result.error.tool).toBe('larastan');
      expect(result.error.reason).toContain('PHP >= 8.1');
    });

    it('returns success: false for invalid JSON', () => {
      const result = larastanAdapter.parseOutput('not valid json {{');

      expect(result.success).toBe(false);
      if (result.success) return;

      expect(result.error.tool).toBe('larastan');
      expect(result.error.reason).toContain('Failed to parse');
    });
  });

  describe('filterToStaged', () => {
    it('filters violations to only staged files', () => {
      const violations: AnalysisViolation[] = [
        {
          tool: 'larastan',
          rule: 'method.notFound',
          severity: 'critical',
          file: 'app/Services/OrderService.php',
          line: 45,
          message: 'error 1',
        },
        {
          tool: 'larastan',
          rule: 'property.notFound',
          severity: 'critical',
          file: 'app/Http/Controllers/OrderController.php',
          line: 23,
          message: 'error 2',
        },
        {
          tool: 'larastan',
          rule: 'argument.type',
          severity: 'critical',
          file: 'app/Models/User.php',
          line: 10,
          message: 'error 3',
        },
      ];

      const staged = ['app/Services/OrderService.php', 'app/Models/User.php'];
      const filtered = larastanAdapter.filterToStaged(violations, staged);

      expect(filtered).toHaveLength(2);
      expect(filtered.map((v) => v.file)).toEqual([
        'app/Services/OrderService.php',
        'app/Models/User.php',
      ]);
    });
  });
});
