import { mkdtemp, rm, readdir, readFile } from 'node:fs/promises';
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
  it('should deploy skills to an IDE directory via copy', async () => {
    const claude = IDE_REGISTRY.find((ide) => ide.id === 'claude-code')!;
    const result = await deploySkillsToIde(claude, tempDir);

    expect(result.success).toBe(true);

    const targetDir = join(tempDir, claude.skillsDir);
    const files = await readdir(targetDir);
    expect(files).toContain('codeguard-setup.md');
    expect(files).toContain('codeguard-run.md');
    expect(files).toContain('codeguard-health.md');
  });

  it('should deploy valid markdown with frontmatter', async () => {
    const claude = IDE_REGISTRY.find((ide) => ide.id === 'claude-code')!;
    await deploySkillsToIde(claude, tempDir);

    const content = await readFile(
      join(tempDir, claude.skillsDir, 'codeguard-setup.md'),
      { encoding: 'utf-8' },
    );
    expect(content).toContain('---');
    expect(content).toContain('name: codeguard-setup');
  });

  it('should detect installed IDEs', async () => {
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
    const files = await readdir(targetDir);
    expect(files).toHaveLength(3); // no duplicates
  });
});
