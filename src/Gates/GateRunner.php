<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Gates;

use Closure;
use Henryavila\Codeguard\Telemetry\EventName;
use Henryavila\Codeguard\Telemetry\EventStatus;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Testing\GateConfig;
use Symfony\Component\Process\Process;

/**
 * Runs a single quality gate as a subprocess and emits paired
 * `gate.started` / `gate.ended` telemetry events around it.
 *
 * Design choices:
 *  - Takes the raw shell command from {@see GateConfig::$command} and
 *    executes it via Process::fromShellCommandline so `./vendor/bin/...`
 *    resolves correctly against the consumer's base path.
 *  - `setTimeout(null)` — infection runs can legitimately exceed
 *    minutes on mid-sized projects; telemetry measures actual wall time.
 *  - stdout/stderr are streamed to an optional sink closure so the
 *    command layer can forward them to Laravel's console output
 *    without this class knowing about `$this->output`.
 *  - Telemetry emits schema-validated extras: `gate` is the canonical
 *    enum value (matches GateConfig key for all current gates) and
 *    `context` is the invocation reason (pre-commit|pre-push|ci|manual).
 */
final class GateRunner
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly string $workingDirectory,
    ) {}

    /**
     * @param  Closure(string $type, string $buffer): void|null  $output  Live output sink; $type is 'out' or 'err'.
     */
    public function run(GateConfig $gate, string $context, ?Closure $output = null): GateRunResult
    {
        $this->recorder->record(
            event: EventName::GateStarted,
            status: EventStatus::Ok,
            durationMs: 0,
            extras: [
                'gate' => $gate->key,
                'context' => $context,
            ],
        );

        $process = Process::fromShellCommandline($gate->command, $this->workingDirectory);
        $process->setTimeout(null);

        $start = hrtime(true);
        $process->run(static function (string $type, string $buffer) use ($output): void {
            if ($output !== null) {
                $output($type, $buffer);
            }
        });
        $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);

        $exitCode = $process->getExitCode() ?? 1;
        $status = $exitCode === 0 ? EventStatus::Ok : EventStatus::Fail;

        $this->recorder->record(
            event: EventName::GateEnded,
            status: $status,
            durationMs: $durationMs,
            extras: [
                'gate' => $gate->key,
                'context' => $context,
            ],
        );

        return new GateRunResult(
            gateKey: $gate->key,
            exitCode: $exitCode,
            durationMs: $durationMs,
        );
    }
}
