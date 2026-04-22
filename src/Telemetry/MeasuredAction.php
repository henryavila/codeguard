<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Telemetry;

use CaptainHook\App\Config;
use CaptainHook\App\Console\IO;
use CaptainHook\App\Hook\Action;
use SebastianFeldmann\Git\Repository;
use Throwable;

/**
 * Decorator for {@see Action} that emits `gate.started` + `gate.ended`
 * telemetry events around the inner action's execution.
 *
 * The decorator is applied programmatically — by codeguard's own command
 * wrappers — rather than via captainhook.json directly, because the
 * CaptainHook config loader instantiates actions with zero-args `new`.
 * Wiring from a consumer's captainhook.json stub to a decorated action
 * will arrive with #15 when the seven instrumentation layers are threaded
 * end-to-end.
 *
 * Extras emitted:
 *   gate.started: {gate, context}
 *   gate.ended:   {gate, context}          — violations_count / files_scanned_count
 *                                            are intentionally omitted here; specific
 *                                            gates that know real counts call Recorder
 *                                            directly with the richer payload.
 *
 * Status on gate.ended mirrors success/failure of the inner `execute`.
 */
final class MeasuredAction implements Action
{
    public function __construct(
        private readonly Action $inner,
        private readonly Recorder $recorder,
        private readonly string $gate,
        private readonly string $context,
    ) {}

    public function execute(
        Config $config,
        IO $io,
        Repository $repository,
        Config\Action $action,
    ): void {
        $this->recorder->record(
            event: EventName::GateStarted,
            status: EventStatus::Ok,
            durationMs: 0,
            extras: [
                'gate' => $this->gate,
                'context' => $this->context,
            ],
        );

        $start = hrtime(true);
        $status = EventStatus::Ok;

        try {
            $this->inner->execute($config, $io, $repository, $action);
        } catch (Throwable $e) {
            $status = EventStatus::Fail;
            throw $e;
        } finally {
            $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);
            $this->recorder->record(
                event: EventName::GateEnded,
                status: $status,
                durationMs: $durationMs,
                extras: [
                    'gate' => $this->gate,
                    'context' => $this->context,
                ],
            );
        }
    }
}
