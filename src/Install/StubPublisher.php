<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Illuminate\Console\OutputStyle;
use Illuminate\Filesystem\Filesystem;

use function Laravel\Prompts\select;

final class StubPublisher
{
    private ?OutputStyle $output = null;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $basePath,
        private readonly string $stubsSourcePath,
        private readonly StubDiffer $differ,
        private readonly StubOverrides $overrides,
    ) {}

    public function useOutput(?OutputStyle $output): void
    {
        $this->output = $output;
    }

    /**
     * @param  list<StubDefinition>  $stubs
     * @return list<StubPublishResult>
     */
    public function publish(array $stubs, bool $forceOverwrite, bool $interactive): array
    {
        $results = [];

        foreach ($stubs as $stub) {
            $results[] = $this->publishOne($stub, $forceOverwrite, $interactive);
        }

        return $results;
    }

    private function publishOne(StubDefinition $stub, bool $forceOverwrite, bool $interactive): StubPublishResult
    {
        $sourcePath = $this->stubsSourcePath.DIRECTORY_SEPARATOR.$stub->stubRelativePath;
        $targetPath = $this->basePath.DIRECTORY_SEPARATOR.$stub->targetRelativePath;

        if (! $this->filesystem->exists($sourcePath)) {
            return $this->failure($stub, $targetPath, "Source stub missing: {$sourcePath}");
        }

        // Permanent skip — unless the user forces overwrite with --refresh-stubs.
        // Checked before exists() so the override is honored even when the
        // target was deleted and would otherwise be re-created from the stub.
        if (! $forceOverwrite && $this->overrides->contains($stub->targetRelativePath)) {
            return new StubPublishResult(
                stub: $stub,
                targetAbsolutePath: $targetPath,
                status: StubPublishStatus::KeptCustomPermanent,
                message: 'Listed in .codeguard/stub-overrides.yaml — skipped.',
            );
        }

        if (! $this->filesystem->exists($targetPath)) {
            return $this->create($stub, $sourcePath, $targetPath);
        }

        $diff = $this->differ->diff($targetPath, $sourcePath);

        if ($diff === null) {
            return new StubPublishResult(
                stub: $stub,
                targetAbsolutePath: $targetPath,
                status: StubPublishStatus::Unchanged,
                message: 'Existing file matches the current stub.',
            );
        }

        return $this->resolveConflict($stub, $sourcePath, $targetPath, $diff, $forceOverwrite, $interactive);
    }

    private function resolveConflict(
        StubDefinition $stub,
        string $sourcePath,
        string $targetPath,
        string $diff,
        bool $forceOverwrite,
        bool $interactive,
    ): StubPublishResult {
        if (! $interactive) {
            if ($forceOverwrite) {
                return $this->overwrite($stub, $sourcePath, $targetPath, $diff);
            }

            return new StubPublishResult(
                stub: $stub,
                targetAbsolutePath: $targetPath,
                status: StubPublishStatus::KeptCustom,
                message: 'Differs from stub — re-run with --refresh-stubs to update.',
                diff: $diff,
            );
        }

        $choice = $this->promptConflictChoice($stub, $diff, $forceOverwrite);

        return match ($choice) {
            'overwrite' => $this->overwrite($stub, $sourcePath, $targetPath, $diff),
            'show-diff' => $this->overwriteAfterDiffReview($stub, $sourcePath, $targetPath, $diff),
            'keep-remember' => $this->keepAndRemember($stub, $targetPath, $diff),
            default => new StubPublishResult(
                stub: $stub,
                targetAbsolutePath: $targetPath,
                status: StubPublishStatus::KeptCustom,
                message: 'Kept existing customizations.',
                diff: $diff,
            ),
        };
    }

    private function promptConflictChoice(StubDefinition $stub, string $diff, bool $forceOverwrite): string
    {
        $summary = $this->summarizeDiff($diff);

        /** @var string $choice */
        $choice = select(
            label: "File {$stub->targetRelativePath} differs from current stub ({$summary}).",
            options: [
                'keep' => 'Keep existing file (ask again next run)',
                'keep-remember' => 'Keep + remember (never ask again for this file)',
                'overwrite' => 'Overwrite with stub (lose customizations)',
                'show-diff' => 'Show full diff, then decide',
            ],
            default: $forceOverwrite ? 'overwrite' : 'keep',
            hint: 'Choose how to handle this conflict.',
        );

        return $choice;
    }

    private function keepAndRemember(
        StubDefinition $stub,
        string $targetPath,
        string $diff,
    ): StubPublishResult {
        $this->overrides->add($stub->targetRelativePath);

        return new StubPublishResult(
            stub: $stub,
            targetAbsolutePath: $targetPath,
            status: StubPublishStatus::KeptCustomPermanent,
            message: 'Kept + remembered — added to .codeguard/stub-overrides.yaml.',
            diff: $diff,
        );
    }

    private function overwriteAfterDiffReview(
        StubDefinition $stub,
        string $sourcePath,
        string $targetPath,
        string $diff,
    ): StubPublishResult {
        $this->writeDiffToOutput($stub, $diff);

        /** @var string $choice */
        $choice = select(
            label: 'After reviewing the diff above, what do you want to do?',
            options: [
                'keep' => 'Keep existing file (ask again next run)',
                'keep-remember' => 'Keep + remember (never ask again for this file)',
                'overwrite' => 'Overwrite with stub',
            ],
            default: 'keep',
        );

        return match ($choice) {
            'overwrite' => $this->overwrite($stub, $sourcePath, $targetPath, $diff),
            'keep-remember' => $this->keepAndRemember($stub, $targetPath, $diff),
            default => new StubPublishResult(
                stub: $stub,
                targetAbsolutePath: $targetPath,
                status: StubPublishStatus::KeptCustom,
                message: 'Kept existing customizations after diff review.',
                diff: $diff,
            ),
        };
    }

    private function writeDiffToOutput(StubDefinition $stub, string $diff): void
    {
        if ($this->output === null) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln("<fg=yellow;options=bold>── Diff for {$stub->targetRelativePath} ──────────────────────────────</>");
        $this->output->writeln('');
        $this->output->writeln($this->differ->colorize($diff));
        $this->output->writeln('');
        $this->output->writeln('<fg=yellow;options=bold>──────────────────────────────────────────────────────────────────</>');
        $this->output->writeln('');
    }

    private function create(StubDefinition $stub, string $sourcePath, string $targetPath): StubPublishResult
    {
        $this->ensureDirectory(dirname($targetPath));

        try {
            $this->filesystem->copy($sourcePath, $targetPath);
        } catch (\Throwable $exception) {
            return $this->failure($stub, $targetPath, $exception->getMessage());
        }

        return new StubPublishResult(
            stub: $stub,
            targetAbsolutePath: $targetPath,
            status: StubPublishStatus::Created,
        );
    }

    private function overwrite(
        StubDefinition $stub,
        string $sourcePath,
        string $targetPath,
        string $diff,
    ): StubPublishResult {
        $this->ensureDirectory(dirname($targetPath));

        try {
            $this->filesystem->copy($sourcePath, $targetPath);
        } catch (\Throwable $exception) {
            return $this->failure($stub, $targetPath, $exception->getMessage());
        }

        return new StubPublishResult(
            stub: $stub,
            targetAbsolutePath: $targetPath,
            status: StubPublishStatus::Overwritten,
            diff: $diff,
        );
    }

    private function failure(StubDefinition $stub, string $targetPath, string $message): StubPublishResult
    {
        return new StubPublishResult(
            stub: $stub,
            targetAbsolutePath: $targetPath,
            status: StubPublishStatus::Failed,
            message: $message,
        );
    }

    private function summarizeDiff(string $diff): string
    {
        $added = 0;
        $removed = 0;

        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                continue;
            }

            if (str_starts_with($line, '+')) {
                $added++;
            } elseif (str_starts_with($line, '-')) {
                $removed++;
            }
        }

        return "+{$added} / -{$removed} lines";
    }

    private function ensureDirectory(string $directory): void
    {
        if (! $this->filesystem->isDirectory($directory)) {
            $this->filesystem->makeDirectory($directory, 0o755, recursive: true);
        }
    }
}
