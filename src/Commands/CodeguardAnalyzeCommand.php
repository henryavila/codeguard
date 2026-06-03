<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Commands;

use Henryavila\Codeguard\Analyze\AnalyzeResult;
use Henryavila\Codeguard\Analyze\AnalyzeRunner;
use Henryavila\Codeguard\Analyze\FileScopeResolver;
use Henryavila\Codeguard\Analyze\Severity;
use Henryavila\Codeguard\Telemetry\EventName;
use Henryavila\Codeguard\Telemetry\EventStatus;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Testing\CodeguardConfig;
use Illuminate\Console\Command;

final class CodeguardAnalyzeCommand extends Command
{
    protected $signature = 'codeguard:analyze
        {--changed-only : Analyze only git-changed + staged files (default scope).}
        {--path= : Narrow scope to a file or subtree.}
        {--all : Full scan of every detection-matched file (CI/manual).}
        {--fail-on=critical : Exit non-zero at/above this severity — critical|warning|suggestion|never.}
        {--context=manual : Telemetry context — pre-commit|pre-push|ci|manual.}';

    protected $description = 'Run pattern-based LLM review over scoped files and report findings.';

    private const ALLOWED_CONTEXTS = ['pre-commit', 'pre-push', 'ci', 'manual'];

    public function handle(
        CodeguardConfig $config,
        AnalyzeRunner $runner,
        FileScopeResolver $scope,
        Recorder $recorder,
    ): int {
        $context = $this->resolveContext();
        $failOn = $this->resolveFailOn();

        $startHrtime = hrtime(true);
        $recorder->record(
            event: EventName::CommandStart,
            status: EventStatus::Ok,
            durationMs: 0,
            extras: [
                'command' => 'analyze',
                'preset_flag' => null,
            ],
        );

        $files = $this->resolveFiles($scope);
        $result = $runner->run($files, $config->enabledPresets, $failOn, $context);

        if (! $result->adjudicated) {
            $this->components->warn(
                'LLM driver not configured — set config(\'codeguard.patterns.driver\'). No patterns adjudicated.',
            );
            $this->emitCommandEnd($recorder, self::SUCCESS, $startHrtime);

            return self::SUCCESS;
        }

        $this->renderFindings($result);

        $exitCode = $result->failed($failOn) ? self::FAILURE : self::SUCCESS;
        $this->emitCommandEnd($recorder, $exitCode, $startHrtime);

        return $exitCode;
    }

    /**
     * @return list<string>
     */
    private function resolveFiles(FileScopeResolver $scope): array
    {
        $path = $this->option('path');
        if (is_string($path) && $path !== '') {
            return $scope->path($path);
        }

        if ((bool) $this->option('all')) {
            return $scope->all();
        }

        return $scope->changedOnly();
    }

    private function resolveFailOn(): ?Severity
    {
        $raw = (string) ($this->option('fail-on') ?: 'critical');

        if ($raw === 'never') {
            return null;
        }

        return Severity::tryFrom($raw) ?? Severity::Critical;
    }

    private function renderFindings(AnalyzeResult $result): void
    {
        if ($result->matches === []) {
            $this->components->info(sprintf('No pattern findings (%d checks).', $result->patternsChecked));

            return;
        }

        foreach ($result->matches as $match) {
            $this->line(sprintf(
                '  %s %s:%d · %s · %s (%.2f)',
                $this->glyph($match->severity),
                $match->file,
                $match->line,
                $match->patternKey,
                $match->message,
                $match->confidence,
            ));
        }

        $this->line('');
        $this->components->info(sprintf(
            '%d finding(s) across %d checks.',
            count($result->matches),
            $result->patternsChecked,
        ));
    }

    private function glyph(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical => '✗',
            Severity::Warning => '⚠',
            Severity::Suggestion => '→',
        };
    }

    private function resolveContext(): string
    {
        $raw = (string) ($this->option('context') ?: 'manual');

        return in_array($raw, self::ALLOWED_CONTEXTS, true) ? $raw : 'manual';
    }

    private function emitCommandEnd(Recorder $recorder, int $exitCode, int $startHrtime): void
    {
        $durationMs = (int) round((hrtime(true) - $startHrtime) / 1_000_000);

        $recorder->record(
            event: EventName::CommandEnd,
            status: $exitCode === 0 ? EventStatus::Ok : EventStatus::Fail,
            durationMs: $durationMs,
            extras: [
                'command' => 'analyze',
                'exit_code' => max(0, min(255, $exitCode)),
            ],
        );
    }
}
