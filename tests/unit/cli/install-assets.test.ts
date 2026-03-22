import { mkdtemp, rm, readFile, stat, readdir } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { describe, it, expect, beforeEach, afterEach } from 'vitest';

import { deployProjectAssets } from '../../../src/cli/skill-deployer.js';

let tempDir: string;

beforeEach(async () => {
  tempDir = await mkdtemp(join(tmpdir(), 'codeguard-test-'));
});

afterEach(async () => {
  await rm(tempDir, { recursive: true, force: true });
});

describe('Project Assets Deployment', () => {
  it('should copy hook-runner to .codeguard/hook-runner.js', async () => {
    const result = await deployProjectAssets(tempDir);
    expect(result.success).toBe(true);

    const hookRunner = join(tempDir, '.codeguard', 'hook-runner.js');
    const s = await stat(hookRunner);
    expect(s.isFile()).toBe(true);

    const content = await readFile(hookRunner, { encoding: 'utf-8' });
    expect(content.length).toBeGreaterThan(0);
  });

  it('should copy modules to .codeguard/modules/', async () => {
    const result = await deployProjectAssets(tempDir);
    expect(result.success).toBe(true);

    const modulesDir = join(tempDir, '.codeguard', 'modules');
    const s = await stat(modulesDir);
    expect(s.isDirectory()).toBe(true);

    // Should contain php-laravel module
    const entries = await readdir(modulesDir);
    expect(entries).toContain('php-laravel');

    // php-laravel should have module.yaml
    const moduleYaml = join(modulesDir, 'php-laravel', 'module.yaml');
    const ms = await stat(moduleYaml);
    expect(ms.isFile()).toBe(true);
  });

  it('should copy core and php pattern directories', async () => {
    const result = await deployProjectAssets(tempDir);
    expect(result.success).toBe(true);

    const modulesDir = join(tempDir, '.codeguard', 'modules');
    const entries = await readdir(modulesDir);
    expect(entries).toContain('core');
    expect(entries).toContain('php');

    // Core should have patterns
    const corePatterns = await readdir(join(modulesDir, 'core', 'patterns'));
    expect(corePatterns.length).toBeGreaterThan(0);
  });

  it('should be idempotent (re-running overwrites without errors)', async () => {
    await deployProjectAssets(tempDir);
    const result = await deployProjectAssets(tempDir);
    expect(result.success).toBe(true);
  });
});
