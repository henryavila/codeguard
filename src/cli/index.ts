import { createRequire } from 'node:module';

import { Command } from 'commander';

import { runInstall } from './commands/install.js';

const require = createRequire(import.meta.url);
const { version } = require('../../package.json') as { version: string };

const program = new Command();

program
  .name('codeguard')
  .version(version)
  .description('AI-native code governance \u2014 enforces project standards via skills and hooks');

program
  .command('install')
  .description('Install CodeGuard skills to your AI IDEs')
  .action(async () => {
    await runInstall(process.cwd(), version);
  });

program.parse();
