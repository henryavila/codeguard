import { access, cp, mkdir, readdir, stat, symlink, lstat, rm } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { IDE_REGISTRY, type IdeTarget } from './ide-registry.js';
import type { Result } from '../core/types/result.js';

export interface DeployResult {
  ide: IdeTarget;
  success: boolean;
  error?: string;
}

const SKILL_DIRS = ['codeguard-setup', 'codeguard-run', 'codeguard-health'];

/**
 * Resolve the package root (where package.json lives).
 * From compiled output at dist/cli/skill-deployer.js, go up to package root.
 */
function getPackageRoot(): string {
  const currentDir = dirname(fileURLToPath(import.meta.url));
  // Walk up from the current file until we find a directory that looks like the package root.
  // In development (via tsx): src/cli/ -> package root is ../..
  // In production (compiled): dist/cli/ -> package root is ../..
  return resolve(currentDir, '..', '..');
}

function getSkillsSourceDir(): string {
  return join(getPackageRoot(), 'skills');
}

async function deployByCopy(
  skillsSourceDir: string,
  targetDir: string,
): Promise<Result<void>> {
  try {
    await mkdir(targetDir, { recursive: true });
    for (const skillDir of SKILL_DIRS) {
      const srcDir = join(skillsSourceDir, skillDir);
      const destDir = join(targetDir, skillDir);
      // Recursively copy the entire skill directory
      await cp(srcDir, destDir, { recursive: true, force: true });
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
    for (const skillDir of SKILL_DIRS) {
      const sourceDir = resolve(skillsSourceDir, skillDir);
      const targetSkillDir = join(targetDir, skillDir);
      // Remove existing symlink/directory before creating new one
      try {
        await lstat(targetSkillDir);
        await rm(targetSkillDir, { recursive: true, force: true });
      } catch {
        // Target doesn't exist, that's fine
      }
      await symlink(sourceDir, targetSkillDir, 'dir');
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

  // Preflight: verify source skills directory exists
  try {
    await access(skillsSourceDir);
  } catch {
    return {
      ide,
      success: false,
      error: `Skills source directory not found: ${skillsSourceDir}`,
    };
  }

  // Preflight: verify each skill subdirectory exists
  for (const skillDir of SKILL_DIRS) {
    const skillSourcePath = join(skillsSourceDir, skillDir);
    try {
      await access(skillSourcePath);
    } catch {
      return {
        ide,
        success: false,
        error: `Skill source not found: ${skillSourcePath}`,
      };
    }
  }

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

  return results.map((result, i) => {
    if (result.status === 'fulfilled') return result.value;
    return {
      ide: ides[i],
      success: false as const,
      error: result.reason instanceof Error ? result.reason.message : String(result.reason),
    };
  });
}

export async function getInstalledIdes(projectRoot: string): Promise<string[]> {
  const installed: string[] = [];

  for (const ide of IDE_REGISTRY) {
    try {
      const dir = join(projectRoot, ide.skillsDir);
      const entries = await readdir(dir);
      // Check if at least one skill directory exists with a SKILL.md inside
      const hasSkillDir = await Promise.all(
        SKILL_DIRS.map(async (skillDir) => {
          if (!entries.includes(skillDir)) return false;
          try {
            const s = await stat(join(dir, skillDir, 'SKILL.md'));
            return s.isFile();
          } catch {
            return false;
          }
        }),
      );
      if (hasSkillDir.some(Boolean)) {
        installed.push(ide.id);
      }
    } catch {
      // Directory doesn't exist — not installed
    }
  }

  return installed;
}

export async function deployProjectAssets(
  projectRoot: string,
): Promise<Result<void>> {
  try {
    const packageRoot = getPackageRoot();
    const codeguardDir = join(projectRoot, '.codeguard');
    await mkdir(codeguardDir, { recursive: true });

    // Copy hook-runner (verify it exists first)
    const hookRunnerSrc = join(packageRoot, 'dist', 'hooks', 'runner.js');
    try {
      await access(hookRunnerSrc);
    } catch {
      return {
        success: false,
        error: "Hook runner not found. Run 'npm run build' first.",
      };
    }
    const hookRunnerDest = join(codeguardDir, 'hook-runner.js');
    await cp(hookRunnerSrc, hookRunnerDest, { force: true });

    // Recursively copy modules
    const modulesSrc = join(packageRoot, 'modules');
    const modulesDest = join(codeguardDir, 'modules');
    await cp(modulesSrc, modulesDest, { recursive: true, force: true });

    return { success: true, data: undefined };
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return { success: false, error: message };
  }
}
