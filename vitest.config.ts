import { defineConfig } from 'vitest/config';

// NOTE: Config files consumed by external tools MUST use default export.
// The "no default export" convention applies to application code only.
export default defineConfig({
  test: {
    // root: 'tests' sets the test discovery root, NOT the import resolution root.
    // Test file imports remain relative to the file's own location on disk.
    // e.g. from tests/unit/core/types.test.ts → '../../../src/core/types/index.js'
    root: 'tests',
    globals: false,
    environment: 'node',
  },
});
