import { join } from 'node:path';

import { describe, it, expect } from 'vitest';

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

  it('should return error for nonexistent file', async () => {
    const result = await loadModuleDefinition('/nonexistent/module.yaml');
    expect(result.success).toBe(false);
  });
});
