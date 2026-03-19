import { mkdir, copyFile, readdir, symlink, stat, unlink } from 'node:fs/promises';
import { join, resolve } from 'node:path';

import type { IdeTarget } from './ide-registry.js';
import type { Result } from '../core/types/result.js';

export interface DeployResult {
  ide: IdeTarget;
  success: boolean;
  error?: string;
}

const SKILL_FILES = ['codeguard-setup.md', 'codeguard-run.md', 'codeguard-health.md'];

function getSkillsSourceDir(): string {
  // Skills ship inside the npm package at skills/
  // import.meta.dirname gives us the directory of the current file (dist/cli/)
  // We need to go up to the package root, then into skills/
  return join(import.meta.dirname, '..', '..', 'skills');
}

async function deployByCopy(
  skillsSourceDir: string,
  targetDir: string,
): Promise<Result<void>> {
  try {
    await mkdir(targetDir, { recursive: true });
    for (const file of SKILL_FILES) {
      await copyFile(join(skillsSourceDir, file), join(targetDir, file));
    }
    return { success: true, data: undefined };
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return { success: false, error: message };
  }
}

async function deployBySymlink(
  skillsSourceDir: string,
  targetDir: string,
): Promise<Result<void>> {
  try {
    await mkdir(targetDir, { recursive: true });
    for (const file of SKILL_FILES) {
      const source = resolve(skillsSourceDir, file);
      const target = join(targetDir, file);
      // Remove existing symlink/file before creating new one
      try {
        await stat(target);
        await unlink(target);
      } catch {
        // Target doesn't exist, that's fine
      }
      await symlink(source, target);
    }
    return { success: true, data: undefined };
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return { success: false, error: message };
  }
}

export async function deploySkillsToIde(
  ide: IdeTarget,
  projectRoot: string,
): Promise<DeployResult> {
  const skillsSourceDir = getSkillsSourceDir();
  const targetDir = join(projectRoot, ide.skillsDir);

  let result: Result<void>;
  switch (ide.mechanism) {
    case 'copy':
    case 'plugin-hook': // plugin-hook uses copy for file deployment; hook registration is separate
      result = await deployByCopy(skillsSourceDir, targetDir);
      break;
    case 'symlink':
      result = await deployBySymlink(skillsSourceDir, targetDir);
      break;
  }

  if (result.success) {
    return { ide, success: true };
  }
  return { ide, success: false, error: result.error };
}

export async function deploySkillsToMultipleIdes(
  ides: IdeTarget[],
  projectRoot: string,
): Promise<DeployResult[]> {
  const results = await Promise.allSettled(
    ides.map((ide) => deploySkillsToIde(ide, projectRoot)),
  );

  return results.map((result) => {
    if (result.status === 'fulfilled') return result.value;
    return {
      ide: { id: 'unknown', label: 'Unknown', skillsDir: '', mechanism: 'copy' as const },
      success: false,
      error: result.reason instanceof Error ? result.reason.message : String(result.reason),
    };
  });
}

export async function getInstalledIdes(projectRoot: string): Promise<string[]> {
  const { IDE_REGISTRY } = await import('./ide-registry.js');
  const installed: string[] = [];

  for (const ide of IDE_REGISTRY) {
    try {
      const dir = join(projectRoot, ide.skillsDir);
      const files = await readdir(dir);
      if (SKILL_FILES.some((sf) => files.includes(sf))) {
        installed.push(ide.id);
      }
    } catch {
      // Directory doesn't exist — not installed
    }
  }

  return installed;
}
