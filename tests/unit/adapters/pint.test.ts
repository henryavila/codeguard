import { describe, it, expect } from 'vitest';

import { pintAdapter } from '../../../src/adapters/php-laravel/pint.js';
import type { ToolConfig, AnalysisViolation } from '../../../src/core/types/index.js';

const defaultConfig: ToolConfig = {
  enabled: true,
  binary: 'vendor/bin/pint',
  enforcement: 'autofix',
};

describe('pintAdapter', () => {
  describe('buildCommand', () => {
    it('produces correct args with files', () => {
      const cmd = pintAdapter.buildCommand(
        ['app/Models/User.php', 'app/Services/OrderService.php'],
        defaultConfig,
      );
      expect(cmd.binary).toBe('vendor/bin/pint');
      expect(cmd.args).toEqual([
        'app/Models/User.php',
        'app/Services/OrderService.php',
      ]);
      expect(cmd.timeout).toBe(60_000);
    });

    it('includes --config when config.config is set', () => {
      const config: ToolConfig = { ...defaultConfig, config: 'pint.json' };
      const cmd = pintAdapter.buildCommand(['file.php'], config);
      expect(cmd.args).toContain('--config=pint.json');
    });

    it('includes --preset when config.preset is set', () => {
      const config: ToolConfig = { ...defaultConfig, preset: 'laravel' };
      const cmd = pintAdapter.buildCommand(['file.php'], config);
      expect(cmd.args).toContain('--preset=laravel');
    });

    it('handles multiple files', () => {
      const files = ['a.php', 'b.php', 'c.php'];
      const cmd = pintAdapter.buildCommand(files, defaultConfig);
      expect(cmd.args.slice(0, 3)).toEqual(files);
    });
  });

  describe('parseOutput', () => {
    it('returns success: true with empty violations', () => {
      const result = pintAdapter.parseOutput('some pint output');
      expect(result.success).toBe(true);
      if (!result.success) return;
      expect(result.violations).toEqual([]);
    });
  });

  describe('supportsFix', () => {
    it('is true', () => {
      expect(pintAdapter.supportsFix).toBe(true);
    });
  });

  describe('filterToStaged', () => {
    it('is passthrough — returns same violations', () => {
      const violations: AnalysisViolation[] = [
        {
          tool: 'pint',
          rule: 'format',
          severity: 'suggestion',
          file: 'app/Models/User.php',
          line: 1,
          message: 'formatting issue',
        },
        {
          tool: 'pint',
          rule: 'format',
          severity: 'suggestion',
          file: 'app/Services/OrderService.php',
          line: 5,
          message: 'formatting issue',
        },
      ];

      const staged = ['app/Models/User.php'];
      const filtered = pintAdapter.filterToStaged(violations, staged);

      // Passthrough: returns all violations regardless of staged files
      expect(filtered).toBe(violations);
      expect(filtered).toHaveLength(2);
    });
  });
});
