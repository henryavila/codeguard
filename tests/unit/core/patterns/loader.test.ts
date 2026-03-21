import { join } from 'node:path';

import { describe, it, expect } from 'vitest';

import { loadPatterns, loadPattern } from '../../../../src/core/patterns/loader.js';

const modulesDir = join(import.meta.dirname, '../../../../modules');

describe('Pattern Loader', () => {
  it('should load a single pattern YAML', async () => {
    const result = await loadPattern(join(modulesDir, 'core/patterns/single-responsibility.yaml'));
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
      expect(result.data.length).toBe(13);
      expect(result.data[0].name).toBe('bounded-contexts');
      expect(result.data.some((p) => p.name === 'single-responsibility')).toBe(true);
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
