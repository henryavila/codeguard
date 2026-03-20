import { execFile } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

import { describe, it, expect } from 'vitest';

const execFileAsync = promisify(execFile);
const PROJECT_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');
const BIN = 'bin/codeguard.js';

async function getPackageVersion(): Promise<string> {
  const pkg = JSON.parse(await readFile(join(PROJECT_ROOT, 'package.json'), { encoding: 'utf-8' }));
  return pkg.version;
}

describe('CLI Entry', () => {
  it('should show version with --version flag', async () => {
    const { stdout } = await execFileAsync('node', [BIN, '--version'], {
      cwd: PROJECT_ROOT,
    });
    const expectedVersion = await getPackageVersion();
    expect(stdout.trim()).toBe(expectedVersion);
  });

  it('should show help with --help flag', async () => {
    const { stdout } = await execFileAsync('node', [BIN, '--help'], {
      cwd: PROJECT_ROOT,
    });
    expect(stdout).toContain('codeguard');
    expect(stdout).toContain('install');
  });

  it('should show install help with install --help', async () => {
    const { stdout } = await execFileAsync('node', [BIN, 'install', '--help'], {
      cwd: PROJECT_ROOT,
    });
    expect(stdout).toContain('Install CodeGuard skills');
  });
});
