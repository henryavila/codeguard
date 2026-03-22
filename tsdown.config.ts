import { defineConfig } from 'tsdown';

// NOTE: Config files consumed by external tools MUST use default export.
// The "no default export" convention applies to application code only.
export default defineConfig([
  // Library + CLI (deps external)
  {
    entry: {
      index: 'src/index.ts',
      'cli/index': 'src/cli/index.ts',
    },
    format: 'esm',
    dts: true,
    clean: true,
    outDir: 'dist',
    // With "type": "module" in package.json, use .js/.d.ts extensions (not .mjs/.d.mts)
    fixedExtension: false,
  },
  // Hook runner (self-contained bundle, all deps inlined)
  {
    entry: { 'hooks/runner': 'src/hooks/runner.ts' },
    format: 'esm',
    outDir: 'dist',
    deps: { alwaysBundle: [/.*/] },
    dts: false,
    fixedExtension: false,
  },
]);
