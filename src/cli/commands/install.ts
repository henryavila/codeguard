import { checkbox } from '@inquirer/prompts';
import chalk from 'chalk';

import { IDE_REGISTRY } from '../ide-registry.js';
import { deploySkillsToMultipleIdes, deployProjectAssets, getInstalledIdes } from '../skill-deployer.js';

export async function runInstall(projectRoot: string, version: string): Promise<void> {
  // TTY check
  if (!process.stdout.isTTY) {
    console.error(
      chalk.red('  Interactive terminal required. Run this command in a terminal, not in a pipe or CI.'),
    );
    process.exitCode = 1;
    return;
  }

  // Header
  console.log(`\n${chalk.bold(`codeguard v${version}`)}\n`);

  // Detect already installed IDEs
  const installedIds = await getInstalledIdes(projectRoot);

  // IDE selection
  const selectedIds = await checkbox({
    message: 'Select AI IDEs to install skills for:',
    choices: IDE_REGISTRY.map((ide) => ({
      name: ide.label,
      value: ide.id,
      checked: installedIds.includes(ide.id),
    })),
    validate(items) {
      if (items.length === 0) return 'Select at least one IDE to continue.';
      return true;
    },
  });

  // Deploy
  const selectedIdes = selectedIds
    .map((id) => IDE_REGISTRY.find((ide) => ide.id === id))
    .filter(Boolean) as (typeof IDE_REGISTRY)[number][];

  const results = await deploySkillsToMultipleIdes(selectedIdes, projectRoot);

  // Results
  console.log('');
  const successCount = results.filter((r) => r.success).length;
  const failCount = results.filter((r) => !r.success).length;

  for (const result of results) {
    if (result.success) {
      console.log(chalk.green(`  \u2713 Skills copied to ${result.ide.skillsDir}/`));
    } else {
      console.log(chalk.red(`  \u2717 Failed to copy skills to ${result.ide.skillsDir}/`));
      console.log(chalk.dim(`    Reason: ${result.error}`));
    }
  }

  // Deploy project assets (hook-runner + modules) to .codeguard/ — only if at least one IDE succeeded
  let assetsResult: { success: boolean; error?: string } = { success: true };
  if (successCount > 0) {
    assetsResult = await deployProjectAssets(projectRoot);
    if (assetsResult.success) {
      console.log(chalk.green('  \u2713 Project assets copied to .codeguard/'));
    } else {
      console.log(chalk.red('  \u2717 Failed to copy project assets to .codeguard/'));
      console.log(chalk.dim(`    Reason: ${assetsResult.error}`));
    }
  }

  // Summary
  const assetsFailed = !assetsResult.success;

  console.log('');
  if ((failCount > 0 || assetsFailed) && successCount > 0) {
    console.log(chalk.yellow(`  Completed with errors. Installed for ${successCount} of ${results.length} IDEs.`));
    process.exitCode = 1;
  } else if (failCount > 0 && successCount === 0) {
    console.log(chalk.red('  Installation failed for all selected IDEs.'));
    process.exitCode = 1;
    return;
  }

  // Next step
  console.log(`  Run ${chalk.cyan("'codeguard setup'")} in your AI agent to configure standards.\n`);
}
