import { describe, it, expect } from 'vitest';

import { getStagedFiles, gitAddFiles } from '../../../src/hooks/staged-files.js';

// ---------------------------------------------------------------------------
// Smoke tests — staged-files depends on git and execFile.
// Real behavior is verified in integration tests; here we just confirm
// the module exports the expected functions.
// ---------------------------------------------------------------------------

describe('staged-files module exports', () => {
  it('exports getStagedFiles as a function', () => {
    expect(typeof getStagedFiles).toBe('function');
  });

  it('exports gitAddFiles as a function', () => {
    expect(typeof gitAddFiles).toBe('function');
  });
});
