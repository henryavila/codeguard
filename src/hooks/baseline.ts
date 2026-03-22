import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';

import type { BaselineEntry, BaselineFile, AnalysisViolation } from '../core/types/index.js';

export function computeBaselineHash(
  tool: string,
  rule: string,
  file: string,
  messageNormalized: string,
): string {
  const input = `${tool}|${rule}|${file}|${messageNormalized}`;
  return createHash('sha256').update(input).digest('hex').slice(0, 8);
}

export function normalizeMessage(message: string): string {
  // Strip line/column numbers and specific identifiers for stable matching
  return message
    .replace(/\bline\s+\d+/gi, 'line *')
    .replace(/\bcolumn\s+\d+/gi, 'column *')
    .replace(/:[0-9]+/g, ':*')
    .trim();
}

export function violationToBaselineEntry(v: AnalysisViolation): BaselineEntry {
  const messageNormalized = normalizeMessage(v.message);
  return {
    tool: v.tool,
    rule: v.rule,
    file: v.file,
    message_normalized: messageNormalized,
    hash: computeBaselineHash(v.tool, v.rule, v.file, messageNormalized),
  };
}

export async function loadBaseline(path: string): Promise<BaselineFile | null> {
  try {
    const raw = await readFile(path, { encoding: 'utf-8' });
    return JSON.parse(raw) as BaselineFile;
  } catch {
    return null;
  }
}

export function filterBaselined(
  violations: AnalysisViolation[],
  baseline: BaselineFile | null,
): { active: AnalysisViolation[]; baselinedCount: number } {
  if (!baseline || baseline.entries.length === 0) {
    return { active: violations, baselinedCount: 0 };
  }

  const baselineHashes = new Set(baseline.entries.map((e) => e.hash));
  const active: AnalysisViolation[] = [];
  let baselinedCount = 0;

  for (const v of violations) {
    const entry = violationToBaselineEntry(v);
    if (baselineHashes.has(entry.hash)) {
      baselinedCount++;
    } else {
      active.push(v);
    }
  }

  return { active, baselinedCount };
}
