import { mkdtemp, rm, readdir, readFile, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { describe, it, expect, beforeEach, afterEach } from 'vitest';

import { IDE_REGISTRY } from '../../../src/cli/ide-registry.js';
import { deploySkillsToIde, getInstalledIdes } from '../../../src/cli/skill-deployer.js';

let tempDir: string;

beforeEach(async () => {
  tempDir = await mkdtemp(join(tmpdir(), 'codeguard-test-'));
});

afterEach(async () => {
  await rm(tempDir, { recursive: true, force: true });
});

describe('Skill Deployer', () => {
  it('should deploy skills as directories with SKILL.md inside', async () => {
    const claude = IDE_REGISTRY.find((ide) => ide.id === 'claude-code')!;
    const result = await deploySkillsToIde(claude, tempDir);
    expect(result.success).toBe(true);

    const targetDir = join(tempDir, claude.skillsDir);
    const entries = await readdir(targetDir);
    expect(entries).toContain('codeguard-setup');
    expect(entries).toContain('codeguard-run');
    expect(entries).toContain('codeguard-health');

    // Each should be a directory containing SKILL.md
    const setupStat = await stat(join(targetDir, 'codeguard-setup'));
    expect(setupStat.isDirectory()).toBe(true);

    const skillFile = await readFile(
      join(targetDir, 'codeguard-setup', 'SKILL.md'),
      { encoding: 'utf-8' },
    );
    expect(skillFile).toContain('---');
    expect(skillFile).toContain('name: codeguard-setup');
  });

  it('should detect installed IDEs by checking for skill directories', async () => {
    const claude = IDE_REGISTRY.find((ide) => ide.id === 'claude-code')!;
    await deploySkillsToIde(claude, tempDir);

    const installed = await getInstalledIdes(tempDir);
    expect(installed).toContain('claude-code');
  });

  it('should return empty array when no IDEs installed', async () => {
    const installed = await getInstalledIdes(tempDir);
    expect(installed).toHaveLength(0);
  });

  it('should overwrite existing skills on re-deploy (idempotent)', async () => {
    const claude = IDE_REGISTRY.find((ide) => ide.id === 'claude-code')!;
    await deploySkillsToIde(claude, tempDir);
    await deploySkillsToIde(claude, tempDir);

    const targetDir = join(tempDir, claude.skillsDir);
    const entries = await readdir(targetDir);
    // Should have exactly 3 skill directories, no duplicates
    const skillDirs = entries.filter((e) => e.startsWith('codeguard-'));
    expect(skillDirs).toHaveLength(3);
  });
});
