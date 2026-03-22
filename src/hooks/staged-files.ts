import { execFile } from 'node:child_process';

export function getStagedFiles(extensions: string[] = ['.php']): Promise<string[]> {
  return new Promise((resolve, reject) => {
    execFile(
      'git',
      ['diff', '--cached', '--name-only', '--diff-filter=ACM'],
      { encoding: 'utf-8' },
      (error, stdout) => {
        if (error) {
          reject(new Error(`Failed to get staged files: ${error.message}`));
          return;
        }

        const files = stdout
          .trim()
          .split('\n')
          .filter((f) => f.length > 0)
          .filter((f) => {
            if (extensions.length === 0) return true;
            return extensions.some((ext) => f.endsWith(ext));
          });

        resolve(files);
      },
    );
  });
}

export function gitAddFiles(files: string[]): Promise<void> {
  if (files.length === 0) return Promise.resolve();

  return new Promise((resolve, reject) => {
    execFile('git', ['add', ...files], (error) => {
      if (error) {
        reject(new Error(`Failed to git add files: ${error.message}`));
        return;
      }
      resolve();
    });
  });
}
