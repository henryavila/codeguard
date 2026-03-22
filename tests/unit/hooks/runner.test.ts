import { describe, it, expect, vi, beforeAll } from 'vitest';

import type {
  CapabilityConfig,
  PresetTool,
} from '../../../src/core/types/index.js';

// runner.ts has a top-level main() that calls process.exit, readFile, etc.
// We must stub process.exit and the heavy imports before loading the module.

// Stub process.exit so the top-level main() doesn't kill the test runner
vi.stubGlobal('process', {
  ...process,
  exit: vi.fn(),
});

// Stub heavy modules that main() touches on import
vi.mock('../../../src/hooks/generated/module-registry.js', () => ({
  MODULE_REGISTRY: {},
}));

vi.mock('../../../src/adapters/php-laravel/index.js', () => ({
  larastanAdapter: {},
  pintAdapter: {},
  phpmdAdapter: {},
  pestAdapter: {},
}));

vi.mock('../../../src/hooks/staged-files.js', () => ({
  getStagedFiles: vi.fn().mockResolvedValue([]),
  gitAddFiles: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('../../../src/hooks/baseline.js', () => ({
  loadBaseline: vi.fn().mockResolvedValue(null),
  filterBaselined: vi.fn().mockReturnValue({ active: [], baselinedCount: 0 }),
}));

vi.mock('../../../src/hooks/formatter.js', () => ({
  hookFormatter: {
    formatFindings: vi.fn().mockReturnValue(''),
    formatSummary: vi.fn().mockReturnValue(''),
    formatError: vi.fn().mockReturnValue(''),
  },
}));

vi.mock('yaml', () => ({
  parse: vi.fn().mockReturnValue(null),
}));

// Now we can safely import resolveToolConfig — the top-level main() will run
// but process.exit is stubbed so it won't terminate.
let resolveToolConfig: typeof import('../../../src/hooks/runner.js')['resolveToolConfig'];

beforeAll(async () => {
  const mod = await import('../../../src/hooks/runner.js');
  resolveToolConfig = mod.resolveToolConfig;
});

// ---------------------------------------------------------------------------
// resolveToolConfig
// ---------------------------------------------------------------------------

describe('resolveToolConfig', () => {
  const baseCapability: CapabilityConfig = {
    enabled: true,
    enforcement: 'block',
  };

  const basePreset: PresetTool = {
    binary: 'vendor/bin/larastan',
    config: 'phpstan.neon',
    level: 5,
    extensions: ['.php'],
    directory: 'app',
    rulesets: ['cleancode', 'codesize'],
    preset: 'laravel',
  };

  it('merges CapabilityConfig and PresetTool correctly', () => {
    const result = resolveToolConfig(baseCapability, basePreset);

    expect(result.enabled).toBe(true);
    expect(result.enforcement).toBe('block');
    expect(result.binary).toBe('vendor/bin/larastan');
    expect(result.config).toBe('phpstan.neon');
    expect(result.extensions).toEqual(['.php']);
    expect(result.directory).toBe('app');
    expect(result.rulesets).toEqual(['cleancode', 'codesize']);
    expect(result.preset).toBe('laravel');
  });

  it('takes enabled from capability', () => {
    const disabled: CapabilityConfig = { ...baseCapability, enabled: false };
    const result = resolveToolConfig(disabled, basePreset);
    expect(result.enabled).toBe(false);
  });

  it('takes enforcement from capability', () => {
    const warn: CapabilityConfig = { ...baseCapability, enforcement: 'warn' };
    const result = resolveToolConfig(warn, basePreset);
    expect(result.enforcement).toBe('warn');
  });

  it('takes binary from preset', () => {
    const customPreset: PresetTool = { ...basePreset, binary: '/usr/local/bin/phpstan' };
    const result = resolveToolConfig(baseCapability, customPreset);
    expect(result.binary).toBe('/usr/local/bin/phpstan');
  });

  it('takes config from preset', () => {
    const customPreset: PresetTool = { ...basePreset, config: 'custom.neon' };
    const result = resolveToolConfig(baseCapability, customPreset);
    expect(result.config).toBe('custom.neon');
  });

  it('takes extensions from preset', () => {
    const customPreset: PresetTool = { ...basePreset, extensions: ['.php', '.blade.php'] };
    const result = resolveToolConfig(baseCapability, customPreset);
    expect(result.extensions).toEqual(['.php', '.blade.php']);
  });

  it('takes directory from preset', () => {
    const customPreset: PresetTool = { ...basePreset, directory: 'src' };
    const result = resolveToolConfig(baseCapability, customPreset);
    expect(result.directory).toBe('src');
  });

  it('takes rulesets from preset', () => {
    const customPreset: PresetTool = { ...basePreset, rulesets: ['unusedcode'] };
    const result = resolveToolConfig(baseCapability, customPreset);
    expect(result.rulesets).toEqual(['unusedcode']);
  });

  it('takes preset name from preset', () => {
    const customPreset: PresetTool = { ...basePreset, preset: 'strict' };
    const result = resolveToolConfig(baseCapability, customPreset);
    expect(result.preset).toBe('strict');
  });

  it('uses capability level when both capability and preset have level', () => {
    const capWithLevel: CapabilityConfig = { ...baseCapability, level: 8 };
    const result = resolveToolConfig(capWithLevel, basePreset);
    expect(result.level).toBe(8);
  });

  it('falls back to preset level when capability level is undefined', () => {
    // baseCapability has no level property
    const result = resolveToolConfig(baseCapability, basePreset);
    expect(result.level).toBe(5);
  });

  it('returns undefined level when neither capability nor preset has level', () => {
    const presetNoLevel: PresetTool = { binary: 'vendor/bin/pint' };
    const result = resolveToolConfig(baseCapability, presetNoLevel);
    expect(result.level).toBeUndefined();
  });
});
