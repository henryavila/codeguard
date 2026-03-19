import { readdir } from 'node:fs/promises';
import { join } from 'node:path';

import { loadModuleDefinition } from './loader.js';
import type { ModuleDefinition } from '../types/index.js';

export interface ProjectSignals {
  files: string[];
  dependencies: string[];
}

export async function discoverModules(modulesDir: string): Promise<ModuleDefinition[]> {
  let entries: string[];
  try {
    const dirents = await readdir(modulesDir, { withFileTypes: true });
    entries = dirents.filter((d) => d.isDirectory()).map((d) => d.name);
  } catch {
    return [];
  }

  const modules: ModuleDefinition[] = [];
  for (const entry of entries) {
    const moduleYaml = join(modulesDir, entry, 'module.yaml');
    const result = await loadModuleDefinition(moduleYaml);
    if (result.success) {
      modules.push(result.data);
    }
  }
  return modules;
}

// Matching: ALL required files present AND at least one dependency matches
export function findModuleForProject(
  modules: ModuleDefinition[],
  signals: ProjectSignals,
): ModuleDefinition | undefined {
  return modules.find((mod) => {
    const requiredFiles = mod.detection.files;
    const allFilesPresent = requiredFiles.every((f) => signals.files.includes(f));
    if (!allFilesPresent) return false;

    const requiredDeps = mod.detection.dependencies ?? [];
    if (requiredDeps.length === 0) return allFilesPresent;

    return requiredDeps.some((dep) => signals.dependencies.includes(dep));
  });
}
