import { execFile } from 'node:child_process';

import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('node:child_process', () => ({
  execFile: vi.fn(),
}));

const mockExecFile = vi.mocked(execFile);

import { getStagedFiles, gitAddFiles } from '../../../src/hooks/staged-files.js';

// ---------------------------------------------------------------------------
// Helper — make mockExecFile invoke the callback with the given result
// ---------------------------------------------------------------------------
type ExecFileCallback = (error: Error | null, stdout: string, stderr: string) => void;

function mockExecFileResult(stdout: string, error?: Error) {
  mockExecFile.mockImplementation((_cmd, _args, _opts, callback) => {
    const cb = callback as ExecFileCallback;
    if (error) {
      cb(error, '', '');
    } else {
      cb(null, stdout, '');
    }
    return undefined as never;
  });
}

beforeEach(() => {
  mockExecFile.mockReset();
});

// ---------------------------------------------------------------------------
// getStagedFiles
// ---------------------------------------------------------------------------
describe('getStagedFiles', () => {
  it('parses multi-line git output into file array', async () => {
    mockExecFileResult(
      'app/Http/Controllers/OrderController.php\napp/Services/OrderService.php\n',
    );

    const files = await getStagedFiles();

    expect(files).toEqual([
      'app/Http/Controllers/OrderController.php',
      'app/Services/OrderService.php',
    ]);
  });

  it('filters by extension (.php)', async () => {
    mockExecFileResult(
      'app/Http/Controllers/OrderController.php\nREADME.md\napp/Services/OrderService.php\npackage.json\n',
    );

    const files = await getStagedFiles(['.php']);

    expect(files).toEqual([
      'app/Http/Controllers/OrderController.php',
      'app/Services/OrderService.php',
    ]);
  });

  it('returns empty array when no staged files', async () => {
    mockExecFileResult('');

    const files = await getStagedFiles();

    expect(files).toEqual([]);
  });

  it('returns empty array when no .php files in staged files', async () => {
    mockExecFileResult('README.md\npackage.json\n');

    const files = await getStagedFiles(['.php']);

    expect(files).toEqual([]);
  });

  it('handles git command error', async () => {
    mockExecFileResult('', new Error('not a git repository'));

    await expect(getStagedFiles()).rejects.toThrow(
      'Failed to get staged files: not a git repository',
    );
  });
});

// ---------------------------------------------------------------------------
// gitAddFiles
// ---------------------------------------------------------------------------
type GitAddCallback = (error: Error | null) => void;

describe('gitAddFiles', () => {
  it('calls git add with provided files', async () => {
    mockExecFile.mockImplementation((_cmd, _args, callback) => {
      (callback as GitAddCallback)(null);
      return undefined as never;
    });

    await gitAddFiles([
      'app/Http/Controllers/OrderController.php',
      'app/Services/OrderService.php',
    ]);

    expect(mockExecFile).toHaveBeenCalledWith(
      'git',
      [
        'add',
        'app/Http/Controllers/OrderController.php',
        'app/Services/OrderService.php',
      ],
      expect.any(Function),
    );
  });

  it('resolves immediately for empty file array', async () => {
    await gitAddFiles([]);

    expect(mockExecFile).not.toHaveBeenCalled();
  });

  it('rejects when git add fails', async () => {
    mockExecFile.mockImplementation((_cmd, _args, callback) => {
      (callback as GitAddCallback)(new Error('permission denied'));
      return undefined as never;
    });

    await expect(gitAddFiles(['file.php'])).rejects.toThrow(
      'Failed to git add files: permission denied',
    );
  });
});
