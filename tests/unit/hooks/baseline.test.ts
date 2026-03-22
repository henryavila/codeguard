import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { describe, it, expect } from 'vitest';

import type {
  AnalysisViolation,
  BaselineFile,
} from '../../../src/core/types/index.js';
import {
  computeBaselineHash,
  normalizeMessage,
  violationToBaselineEntry,
  filterBaselined,
  loadBaseline,
} from '../../../src/hooks/baseline.js';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeViolation(overrides: Partial<AnalysisViolation> = {}): AnalysisViolation {
  return {
    tool: 'larastan',
    rule: 'missingType.return',
    severity: 'critical',
    file: 'app/Models/User.php',
    line: 42,
    message: 'Method getFullName() has no return type specified.',
    ...overrides,
  };
}

// ---------------------------------------------------------------------------
// computeBaselineHash
// ---------------------------------------------------------------------------

describe('computeBaselineHash', () => {
  it('produces a consistent 8-char hex string', () => {
    const hash = computeBaselineHash('larastan', 'missingType.return', 'app/Models/User.php', 'Method getFullName() has no return type specified.');
    expect(hash).toMatch(/^[0-9a-f]{8}$/);

    // Same inputs must produce the same hash
    const hash2 = computeBaselineHash('larastan', 'missingType.return', 'app/Models/User.php', 'Method getFullName() has no return type specified.');
    expect(hash2).toBe(hash);
  });

  it('produces different hashes for different inputs', () => {
    const hash1 = computeBaselineHash('larastan', 'missingType.return', 'app/Models/User.php', 'msg a');
    const hash2 = computeBaselineHash('larastan', 'missingType.return', 'app/Models/Post.php', 'msg a');
    const hash3 = computeBaselineHash('phpmd', 'missingType.return', 'app/Models/User.php', 'msg a');
    const hash4 = computeBaselineHash('larastan', 'otherRule', 'app/Models/User.php', 'msg a');

    expect(hash1).not.toBe(hash2); // different file
    expect(hash1).not.toBe(hash3); // different tool
    expect(hash1).not.toBe(hash4); // different rule
  });
});

// ---------------------------------------------------------------------------
// normalizeMessage
// ---------------------------------------------------------------------------

describe('normalizeMessage', () => {
  it('strips line numbers', () => {
    expect(normalizeMessage('Error on line 42')).toBe('Error on line *');
    expect(normalizeMessage('error at LINE 100')).toBe('error at line *');
  });

  it('strips column numbers', () => {
    expect(normalizeMessage('Error at column 5')).toBe('Error at column *');
    expect(normalizeMessage('at COLUMN 99')).toBe('at column *');
  });

  it('strips colon-number patterns', () => {
    expect(normalizeMessage('file.php:10')).toBe('file.php:*');
    expect(normalizeMessage('file.php:10:5')).toBe('file.php:*:*');
  });

  it('trims whitespace', () => {
    expect(normalizeMessage('  hello  ')).toBe('hello');
  });
});

// ---------------------------------------------------------------------------
// violationToBaselineEntry
// ---------------------------------------------------------------------------

describe('violationToBaselineEntry', () => {
  it('creates a correct entry from a violation', () => {
    const v = makeViolation();
    const entry = violationToBaselineEntry(v);

    expect(entry.tool).toBe(v.tool);
    expect(entry.rule).toBe(v.rule);
    expect(entry.file).toBe(v.file);
    expect(entry.message_normalized).toBe(normalizeMessage(v.message));
    expect(entry.hash).toMatch(/^[0-9a-f]{8}$/);
  });

  it('normalizes the message before hashing', () => {
    const v1 = makeViolation({ message: 'Error on line 10' });
    const v2 = makeViolation({ message: 'Error on line 999' });

    const entry1 = violationToBaselineEntry(v1);
    const entry2 = violationToBaselineEntry(v2);

    // Same normalized message => same hash
    expect(entry1.hash).toBe(entry2.hash);
    expect(entry1.message_normalized).toBe(entry2.message_normalized);
  });
});

// ---------------------------------------------------------------------------
// filterBaselined
// ---------------------------------------------------------------------------

describe('filterBaselined', () => {
  it('returns all violations as active when baseline is null', () => {
    const violations = [makeViolation(), makeViolation({ file: 'other.php' })];
    const result = filterBaselined(violations, null);

    expect(result.active).toHaveLength(2);
    expect(result.baselinedCount).toBe(0);
  });

  it('returns all violations as active when baseline has empty entries', () => {
    const violations = [makeViolation()];
    const baseline: BaselineFile = {
      version: '1',
      generated: '2026-03-20',
      generatedBy: 'codeguard',
      module: 'php-laravel',
      entries: [],
    };
    const result = filterBaselined(violations, baseline);

    expect(result.active).toHaveLength(1);
    expect(result.baselinedCount).toBe(0);
  });

  it('filters out known violations from baseline', () => {
    const v = makeViolation();
    const entry = violationToBaselineEntry(v);

    const baseline: BaselineFile = {
      version: '1',
      generated: '2026-03-20',
      generatedBy: 'codeguard',
      module: 'php-laravel',
      entries: [entry],
    };

    const result = filterBaselined([v], baseline);

    expect(result.active).toHaveLength(0);
    expect(result.baselinedCount).toBe(1);
  });

  it('correctly counts baselined vs active violations', () => {
    const baselinedViolation = makeViolation();
    const newViolation = makeViolation({
      file: 'app/Services/PaymentService.php',
      message: 'Undefined variable $amount',
      rule: 'variable.undefined',
    });

    const entry = violationToBaselineEntry(baselinedViolation);

    const baseline: BaselineFile = {
      version: '1',
      generated: '2026-03-20',
      generatedBy: 'codeguard',
      module: 'php-laravel',
      entries: [entry],
    };

    const result = filterBaselined([baselinedViolation, newViolation], baseline);

    expect(result.active).toHaveLength(1);
    expect(result.active[0]).toBe(newViolation);
    expect(result.baselinedCount).toBe(1);
  });
});

// ---------------------------------------------------------------------------
// loadBaseline
// ---------------------------------------------------------------------------

describe('loadBaseline', () => {
  it('returns null for a non-existent file', async () => {
    const fakePath = join(tmpdir(), `codeguard-nonexistent-${Date.now()}.json`);
    const result = await loadBaseline(fakePath);
    expect(result).toBeNull();
  });
});
