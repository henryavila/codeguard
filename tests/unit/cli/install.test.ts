import { chmod, mkdir, mkdtemp, rm, readdir } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { describe, it, expect, beforeEach, afterEach } from 'vitest';

import { IDE_REGISTRY } from '../../../src/cli/ide-registry.js';
import { deploySkillsToMultipleIdes } from '../../../src/cli/skill-deployer.js';

let tempDir: string;

beforeEach(async () => {
  tempDir = await mkdtemp(join(tmpdir(), 'codeguard-install-test-'));
});

afterEach(async () => {
  // Restore write permissions so rm can clean up
  try {
    const lockedDir = join(tempDir, 'locked');
    await chmod(lockedDir, 0o755);
  } catch {
    // May not exist
  }
  await rm(tempDir, { recursive: true, force: true });
});

describe('Install Flow', () => {
  it('should deploy skills to multiple IDEs', async () => {
    const ides = IDE_REGISTRY.filter((ide) => ide.mechanism === 'copy').slice(0, 2);
    const results = await deploySkillsToMultipleIdes(ides, tempDir);

    expect(results).toHaveLength(2);
    expect(results.every((r) => r.success)).toBe(true);

    for (const ide of ides) {
      const files = await readdir(join(tempDir, ide.skillsDir));
      expect(files).toContain('codeguard-setup.md');
    }
  });

  it('should handle deployment failure gracefully', async () => {
    // Create a read-only directory so mkdir inside it fails
    const lockedDir = join(tempDir, 'locked');
    await mkdir(lockedDir);
    await chmod(lockedDir, 0o444);

    const ide = IDE_REGISTRY.find((i) => i.id === 'claude-code')!;
    const results = await deploySkillsToMultipleIdes([ide], lockedDir);

    expect(results).toHaveLength(1);
    expect(results[0].success).toBe(false);
    expect(results[0].error).toBeDefined();
  });

  it('should handle mixed success and failure', async () => {
    // Create a read-only directory for the failing case
    const lockedDir = join(tempDir, 'locked');
    await mkdir(lockedDir);
    await chmod(lockedDir, 0o444);

    const ide = IDE_REGISTRY.find((i) => i.id === 'claude-code')!;
    const successResults = await deploySkillsToMultipleIdes([ide], tempDir);
    const failResults = await deploySkillsToMultipleIdes([ide], lockedDir);

    expect(successResults[0].success).toBe(true);
    expect(failResults[0].success).toBe(false);
  });
});
