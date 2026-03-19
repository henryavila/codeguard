import { join } from 'node:path';

import { describe, it, expect } from 'vitest';

import { discoverModules, findModuleForProject } from '../../../../src/core/modules/registry.js';

const modulesDir = join(import.meta.dirname, '../../../../modules');

describe('Module Registry', () => {
  it('should discover available modules', async () => {
    const modules = await discoverModules(modulesDir);
    expect(modules.length).toBeGreaterThan(0);
    expect(modules.some((m) => m.name === 'php-laravel')).toBe(true);
  });

  it('should find module matching project signals', async () => {
    const modules = await discoverModules(modulesDir);
    const result = findModuleForProject(modules, {
      files: ['composer.json', 'artisan'],
      dependencies: ['laravel/framework'],
    });
    expect(result).toBeTruthy();
    expect(result?.name).toBe('php-laravel');
  });

  it('should return undefined when no module matches', async () => {
    const modules = await discoverModules(modulesDir);
    const result = findModuleForProject(modules, {
      files: ['package.json'],
      dependencies: ['react'],
    });
    expect(result).toBeUndefined();
  });

  it('should not match when files present but dependencies missing', async () => {
    const modules = await discoverModules(modulesDir);
    const result = findModuleForProject(modules, {
      files: ['composer.json', 'artisan'],
      dependencies: ['symfony/framework-bundle'],
    });
    expect(result).toBeUndefined();
  });
});
