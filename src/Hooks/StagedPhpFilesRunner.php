<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Hooks;

use CaptainHook\App\Config;
use CaptainHook\App\Console\IO;
use CaptainHook\App\Exception\ActionFailed;
use CaptainHook\App\Hook\Action;
use SebastianFeldmann\Git\Repository;
use Symfony\Component\Process\Process;

/**
 * CaptainHook Action that runs any CLI binary (typically PHPStan) against
 * only the currently staged PHP files, matching what Lefthook's
 * {staged_files} template did for us before the migration (ADR-010).
 *
 * Why this class exists:
 *   CaptainHook's shell-level actions have no built-in {staged_files}
 *   expansion. Without this runner, a configuration like
 *   `vendor/bin/phpstan analyse` would re-scan the whole repo on every
 *   pre-commit — turning a 5s hook into 5+ minutes on Arch-sized projects.
 *
 * Options accepted from captainhook.json:
 *   - binary (string, default: "vendor/bin/phpstan")
 *   - flags  (list<string>, default: ["analyse", "--no-progress"])
 *
 * The action is a no-op when no PHP files are staged; staged-file
 * discovery uses sebastianfeldmann/git's Index operator, which is the
 * same source of truth CaptainHook uses for its FileStaged conditions.
 */
final class StagedPhpFilesRunner implements Action
{
    public function execute(Config $config, IO $io, Repository $repository, Config\Action $action): void
    {
        $options = $action->getOptions();
        $binary = (string) $options->get('binary', 'vendor/bin/phpstan');

        /** @var list<string> $flags */
        $flags = (array) $options->get('flags', ['analyse', '--no-progress']);

        $stagedPhpFiles = $repository->getIndexOperator()->getStagedFilesOfType('php');

        if ($stagedPhpFiles === []) {
            $io->write('No staged PHP files — skipping '.basename($binary).'.', true);

            return;
        }

        $argv = [$binary, ...$flags, ...$stagedPhpFiles];

        $process = new Process($argv);
        $process->setTimeout(null);

        $process->run(static function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });

        if (! $process->isSuccessful()) {
            throw new ActionFailed(sprintf(
                '%s failed with exit code %d',
                basename($binary),
                $process->getExitCode() ?? -1,
            ));
        }
    }
}
