import { readFile, readdir } from 'node:fs/promises';
import { join } from 'node:path';

import { parse } from 'yaml';

import type { PatternDefinition } from '../types/modules.js';
import type { Result } from '../types/result.js';

type PatternsResult =
  | { success: true; data: PatternDefinition[]; warnings: string[] }
  | { success: false; error: string };

function validatePattern(parsed: unknown, filePath: string): Result<PatternDefinition> {
  if (typeof parsed !== 'object' || parsed === null) {
    return { success: false, error: `Pattern file is not a valid object: ${filePath}` };
  }

  const obj = parsed as Record<string, unknown>;

  if (typeof obj.name !== 'string' || obj.name.length === 0) {
    return { success: false, error: `Pattern missing required field "name": ${filePath}` };
  }

  const verification = obj.verification as Record<string, unknown> | undefined;
  if (
    !verification ||
    !Array.isArray(verification.rules) ||
    verification.rules.length === 0
  ) {
    return {
      success: false,
      error: `Pattern missing required field "verification.rules": ${filePath}`,
    };
  }

  return { success: true, data: parsed as PatternDefinition };
}

export async function loadPattern(filePath: string): Promise<Result<PatternDefinition>> {
  let raw: string;
  try {
    raw = await readFile(filePath, { encoding: 'utf-8' });
  } catch {
    return { success: false, error: `Failed to read pattern file: ${filePath}` };
  }

  let parsed: unknown;
  try {
    parsed = parse(raw);
  } catch {
    return { success: false, error: `Failed to parse YAML: ${filePath}` };
  }

  return validatePattern(parsed, filePath);
}

export async function loadPatterns(directoryPath: string): Promise<PatternsResult> {
  let entries: string[];
  try {
    entries = await readdir(directoryPath);
  } catch {
    return { success: false, error: `Failed to read directory: ${directoryPath}` };
  }

  const yamlFiles = entries
    .filter((f) => f.endsWith('.yaml') || f.endsWith('.yml'))
    .sort();

  const patterns: PatternDefinition[] = [];
  const warnings: string[] = [];

  for (const file of yamlFiles) {
    const result = await loadPattern(join(directoryPath, file));
    if (result.success) {
      patterns.push(result.data);
    } else {
      warnings.push(result.error);
    }
  }

  return { success: true, data: patterns, warnings };
}
