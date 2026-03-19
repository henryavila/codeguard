import { readFile } from 'node:fs/promises';

import { default as Ajv } from 'ajv';
import { parse } from 'yaml';

import { configSchema } from './schema.js';
import type { CodeGuardConfig } from '../types/config.js';
import type { Result } from '../types/result.js';

const ajv = new Ajv({ allErrors: true });
const validate = ajv.compile(configSchema);

export async function loadConfig(filePath: string): Promise<Result<CodeGuardConfig>> {
  let raw: string;
  try {
    raw = await readFile(filePath, { encoding: 'utf-8' });
  } catch {
    return { success: false, error: `Failed to read config file: ${filePath}` };
  }

  let parsed: unknown;
  try {
    parsed = parse(raw);
  } catch {
    return { success: false, error: 'Failed to parse YAML' };
  }

  const valid = validate(parsed);
  if (!valid) {
    const messages = validate.errors?.map((e) => `${e.instancePath} ${e.message}`).join('; ') ?? 'Unknown validation error';
    return { success: false, error: `Config validation failed: ${messages}` };
  }

  return { success: true, data: parsed as CodeGuardConfig };
}
