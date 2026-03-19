import { readFile } from 'node:fs/promises';

import { parse } from 'yaml';

import type { ModuleDefinition, PresetDefinition } from '../types/modules.js';
import type { Result } from '../types/result.js';

function validateModuleDefinition(parsed: unknown, filePath: string): Result<ModuleDefinition> {
  if (typeof parsed !== 'object' || parsed === null) {
    return { success: false, error: `Module file is not a valid object: ${filePath}` };
  }

  const obj = parsed as Record<string, unknown>;

  if (typeof obj.name !== 'string' || obj.name.length === 0) {
    return { success: false, error: `Module missing required field "name": ${filePath}` };
  }

  if (typeof obj.language !== 'string' || obj.language.length === 0) {
    return { success: false, error: `Module missing required field "language": ${filePath}` };
  }

  const detection = obj.detection as Record<string, unknown> | undefined;
  if (!detection || !Array.isArray(detection.files) || detection.files.length === 0) {
    return { success: false, error: `Module missing required field "detection.files": ${filePath}` };
  }

  if (typeof obj.capabilities !== 'object' || obj.capabilities === null) {
    return { success: false, error: `Module missing required field "capabilities": ${filePath}` };
  }

  return { success: true, data: parsed as ModuleDefinition };
}

function validatePresetDefinition(parsed: unknown, filePath: string): Result<PresetDefinition> {
  if (typeof parsed !== 'object' || parsed === null) {
    return { success: false, error: `Preset file is not a valid object: ${filePath}` };
  }

  const obj = parsed as Record<string, unknown>;

  if (typeof obj.tools !== 'object' || obj.tools === null) {
    return { success: false, error: `Preset missing required field "tools": ${filePath}` };
  }

  if (!Array.isArray(obj.install_commands)) {
    return { success: false, error: `Preset missing required field "install_commands": ${filePath}` };
  }

  return { success: true, data: parsed as PresetDefinition };
}

export async function loadModuleDefinition(filePath: string): Promise<Result<ModuleDefinition>> {
  let raw: string;
  try {
    raw = await readFile(filePath, { encoding: 'utf-8' });
  } catch {
    return { success: false, error: `Failed to read module file: ${filePath}` };
  }

  let parsed: unknown;
  try {
    parsed = parse(raw);
  } catch {
    return { success: false, error: `Failed to parse YAML: ${filePath}` };
  }

  return validateModuleDefinition(parsed, filePath);
}

export async function loadPresetDefinition(filePath: string): Promise<Result<PresetDefinition>> {
  let raw: string;
  try {
    raw = await readFile(filePath, { encoding: 'utf-8' });
  } catch {
    return { success: false, error: `Failed to read preset file: ${filePath}` };
  }

  let parsed: unknown;
  try {
    parsed = parse(raw);
  } catch {
    return { success: false, error: `Failed to parse YAML: ${filePath}` };
  }

  return validatePresetDefinition(parsed, filePath);
}
