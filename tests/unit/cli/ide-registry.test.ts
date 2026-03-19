import { describe, it, expect } from 'vitest';

import { IDE_REGISTRY, getIdeById } from '../../../src/cli/ide-registry.js';

describe('IDE Registry', () => {
  it('should have 7 IDEs', () => {
    expect(IDE_REGISTRY).toHaveLength(7);
  });

  it('should have unique IDs', () => {
    const ids = IDE_REGISTRY.map((ide) => ide.id);
    expect(new Set(ids).size).toBe(ids.length);
  });

  it('should have non-empty skillsDir for all IDEs', () => {
    for (const ide of IDE_REGISTRY) {
      expect(ide.skillsDir.length).toBeGreaterThan(0);
    }
  });

  it('should find IDE by id', () => {
    const claude = getIdeById('claude-code');
    expect(claude).toBeTruthy();
    expect(claude?.label).toBe('Claude Code');
    expect(claude?.mechanism).toBe('copy');
  });

  it('should return undefined for unknown id', () => {
    expect(getIdeById('nonexistent')).toBeUndefined();
  });

  it('should include all expected IDEs', () => {
    const ids = IDE_REGISTRY.map((ide) => ide.id);
    expect(ids).toContain('claude-code');
    expect(ids).toContain('cursor');
    expect(ids).toContain('codex-cli');
    expect(ids).toContain('opencode');
    expect(ids).toContain('gemini-cli');
    expect(ids).toContain('copilot-cli');
    expect(ids).toContain('windsurf');
  });
});
