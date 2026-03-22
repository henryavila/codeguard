import { join } from 'node:path';

import { describe, it, expect } from 'vitest';

import { loadConfig } from '../../../../src/core/config/loader.js';

const fixturesDir = join(import.meta.dirname, '../../../fixtures');

describe('Config Loader', () => {
  it('should load a valid codeguard.yaml', async () => {
    const result = await loadConfig(join(fixturesDir, 'codeguard.yaml'));
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.version).toBe('1.0');
      expect(result.data.project.framework).toBe('laravel');
      expect(result.data.capabilities['static-analysis'].level).toBe(5);
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
