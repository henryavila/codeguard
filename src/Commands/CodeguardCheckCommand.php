<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Commands;

use Henryavila\Codeguard\Gates\GateRunner;
use Henryavila\Codeguard\Telemetry\EventName;
use Henryavila\Codeguard\Telemetry\EventStatus;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Testing\CodeguardConfig;
use Henryavila\Codeguard\Testing\GateConfig;
use Illuminate\Console\Command;

final class CodeguardCheckCommand extends Command
{
    protected $signature = 'codeguard:check
        {--gate=* : Limit run to these gate keys (repeatable). When omitted, runs every enabled gate.}
        {--all : Continue running remaining gates after a failure (default is fail-fast).}
        {--context=manual : Telemetry context — pre-commit|pre-push|ci|manual.}';

    protected $description = 'Run the configured quality gates sequentially and report pass/fail.';

    private const ALLOWED_CONTEXTS = ['pre-commit', 'pre-push', 'ci', 'manual'];

    public function handle(CodeguardConfig $config, GateRunner $runner, Recorder $recorder): int
    {
        $context = $this->resolveContext();
        $failFast = ! (bool) $this->option('all');
        $filter = $this->normalizeGateFilter();

        $startHrtime = hrtime(true);
        $recorder->record(
            event: EventName::CommandStart,
            status: EventStatus::Ok,
            durationMs: 0,
            extras: [
                'command' => 'check',
                'preset_flag' => null,
            ],
        );

        $gates = $this->selectGates($config, $filter);
        if ($gates === []) {
            $this->components->warn('No matching gates to run.');
            $this->emitCommandEnd($recorder, self::SUCCESS, $startHrtime);

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Running %d gate(s) — context: %s', count($gates), $context));

        $failures = [];
        foreach ($gates as $gate) {
            $this->line('');
            $this->components->task(
                "{$gate->key} — {$gate->description}",
                function () use ($runner, $gate, $context, &$failures): bool {
                    $result = $runner->run($gate, $context, function (string $_type, string $buffer): void {
                        $this->getOutput()->write($buffer);
                    });

                    if ($result->failed()) {
                        $failures[] = $result->gateKey;

                        return false;
                    }

                    return true;
                },
            );

            if ($failFast && $failures !== []) {
                $this->components->warn('Fail-fast: stopping after first failure. Use --all to continue.');
                break;
            }
        }

        $exitCode = $failures === [] ? self::SUCCESS : self::FAILURE;

        $this->line('');
        if ($exitCode === self::SUCCESS) {
            $this->components->info('All gates passed.');
        } else {
            $this->components->error(sprintf('%d gate(s) failed: %s', count($failures), implode(', ', $failures)));
        }

        $this->emitCommandEnd($recorder, $exitCode, $startHrtime);

        return $exitCode;
    }

    private function resolveContext(): string
    {
        $raw = (string) ($this->option('context') ?: 'manual');

        return in_array($raw, self::ALLOWED_CONTEXTS, true) ? $raw : 'manual';
    }

    /**
     * @return list<string>
     */
    private function normalizeGateFilter(): array
    {
        /** @var list<string> $raw */
        $raw = (array) $this->option('gate');
        $normalised = [];
        foreach ($raw as $entry) {
            // `--gate=pint,phpstan` should work too.
            foreach (explode(',', $entry) as $part) {
                $trim = trim($part);
                if ($trim !== '') {
                    $normalised[] = $trim;
                }
            }
        }

        return array_values(array_unique($normalised));
    }

    /**
     * @param  list<string>  $filter
     * @return list<GateConfig>
     */
    private function selectGates(CodeguardConfig $config, array $filter): array
    {
        $enabled = $config->enabledGates();

        if ($filter === []) {
            return $enabled;
        }

        $unknown = array_diff($filter, array_map(static fn (GateConfig $g): string => $g->key, $enabled));
        if ($unknown !== []) {
            $this->components->warn('Unknown or disabled gate(s) ignored: '.implode(', ', $unknown));
        }

        return array_values(array_filter(
            $enabled,
            static fn (GateConfig $g): bool => in_array($g->key, $filter, true),
        ));
    }

    private function emitCommandEnd(Recorder $recorder, int $exitCode, int $startHrtime): void
    {
        $durationMs = (int) round((hrtime(true) - $startHrtime) / 1_000_000);

        $recorder->record(
            event: EventName::CommandEnd,
            status: $exitCode === 0 ? EventStatus::Ok : EventStatus::Fail,
            durationMs: $durationMs,
            extras: [
                'command' => 'check',
                'exit_code' => max(0, min(255, $exitCode)),
            ],
        );
    }
}
