<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Telemetry;

use Closure;
use Throwable;

/**
 * Tiny scope-guard helper for timing arbitrary callables and recording a
 * single *.ended telemetry event with `duration_ms` populated from hrtime.
 *
 * Semantics:
 *  - Duration is measured with `hrtime(true)` (nanosecond monotonic clock)
 *    and converted to milliseconds on emit.
 *  - `EventStatus::Fail` is emitted when the callable throws; the exception
 *    propagates unchanged — this class observes, it never swallows.
 *  - The return value of the callable is passed through transparently.
 *
 * Designed as a companion to {@see Recorder}; use it when you want a
 * single "ended" event rather than paired start/end (gate-level pairing
 * is the job of {@see MeasuredAction}).
 */
final class StopwatchScope
{
    public function __construct(
        private readonly Recorder $recorder,
    ) {}

    /**
     * @template TReturn
     *
     * @param  array<string, mixed>  $extras
     * @param  Closure(): TReturn  $callable
     * @return TReturn
     */
    public function measure(
        EventName $endEvent,
        array $extras,
        Closure $callable,
    ): mixed {
        $start = hrtime(true);
        $status = EventStatus::Ok;

        try {
            return $callable();
        } catch (Throwable $e) {
            $status = EventStatus::Fail;
            throw $e;
        } finally {
            $elapsedNs = hrtime(true) - $start;
            $durationMs = (int) round($elapsedNs / 1_000_000);

            $this->recorder->record($endEvent, $status, $durationMs, $extras);
        }
    }
}
