import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

import { describe, it, expect } from 'vitest';

const execFileAsync = promisify(execFile);
const BIN = 'bin/codeguard.js';

describe('CLI Entry', () => {
  it('should show version with --version flag', async () => {
    const { stdout } = await execFileAsync('node', [BIN, '--version'], {
      cwd: '/home/henry/codeguard-phase2',
    });
    expect(stdout.trim()).toBe('0.0.0');
  });

  it('should show help with --help flag', async () => {
    const { stdout } = await execFileAsync('node', [BIN, '--help'], {
      cwd: '/home/henry/codeguard-phase2',
    });
    expect(stdout).toContain('codeguard');
    expect(stdout).toContain('install');
  });

  it('should show install help with install --help', async () => {
    const { stdout } = await execFileAsync('node', [BIN, 'install', '--help'], {
      cwd: '/home/henry/codeguard-phase2',
    });
    expect(stdout).toContain('Install CodeGuard skills');
  });
});
