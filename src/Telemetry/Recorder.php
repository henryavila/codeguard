<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Telemetry;

use Closure;
use DateTimeImmutable;
use LogicException;
use Throwable;

/**
 * Single public entry point for telemetry emission.
 *
 * Pipeline per event:
 *  1. Short-circuit if the gate is disabled (zero work, zero syscalls).
 *  2. Validate extras via {@see FieldAllowlist}.
 *     - In strict mode this may throw — we catch and best-effort-skip so a
 *       misbehaving emitter never crashes the user's command.
 *  3. Compute dropped keys (non-strict soft-drops + the noop strict passthrough)
 *     and emit a `telemetry.dropped_field` meta-event per dropped key — but
 *     ONLY when the root event isn't itself the meta-event (loop guard).
 *  4. Ask {@see Rotator} to rotate-if-needed, then append via {@see JsonlWriter}.
 *
 * Telemetry is best-effort: all I/O failures are swallowed by design.
 */
final class Recorder
{
    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly ConfigGate $gate,
        private readonly FieldAllowlist $allowlist,
        private readonly Rotator $rotator,
        private readonly JsonlWriter $writer,
        private readonly string $activePath,
        private readonly ?Closure $clock = null,
    ) {}

    /**
     * @param  array<string, mixed>  $extras
     */
    public function record(
        EventName $event,
        EventStatus $status,
        int $durationMs,
        array $extras = [],
    ): void {
        if (! $this->gate->isEnabled()) {
            return;
        }

        $normalised = $this->safelyValidate($event, $extras);
        if ($normalised === null) {
            return;
        }

        $this->writeEnvelope($event, $status, $durationMs, $normalised);

        $dropped = array_diff_key($extras, $normalised);
        if ($event === EventName::TelemetryDroppedField || $dropped === []) {
            return;
        }

        foreach (array_keys($dropped) as $field) {
            if (! is_string($field)) {
                continue;
            }
            $this->emitDroppedMeta($event, $field);
        }
    }

    /**
     * @param  array<string, mixed>  $extras
     * @return array<string, mixed>|null null when allowlist throws in strict mode
     */
    private function safelyValidate(EventName $event, array $extras): ?array
    {
        try {
            return $this->allowlist->validate($event, $extras);
        } catch (LogicException) {
            // In strict mode this is the intended dev-time signal. Telemetry
            // itself must not crash the user command, so we skip this event.
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $extras
     */
    private function writeEnvelope(
        EventName $event,
        EventStatus $status,
        int $durationMs,
        array $extras,
    ): void {
        try {
            $envelope = new Event(
                ts: $this->nowIso(),
                event: $event,
                status: $status,
                durationMs: $durationMs,
                extras: $extras,
            );

            $this->rotator->rotateIfNeeded($this->activePath);
            $this->writer->append($this->activePath, $envelope->toArray());
        } catch (Throwable) {
            // Swallow — telemetry is best-effort.
        }
    }

    private function emitDroppedMeta(EventName $rootEvent, string $field): void
    {
        // If the dropped field isn't declared anywhere in the schema, the
        // meta event would itself fail validation on field_name. Skip the
        // meta entirely to avoid writing a crippled envelope; the envelope
        // with the dropped field already captured that "something happened".
        if (! in_array($field, FieldAllowlist::allFieldNames(), true)) {
            return;
        }

        $this->record(
            event: EventName::TelemetryDroppedField,
            status: EventStatus::Skip,
            durationMs: 0,
            extras: [
                'target_event' => $rootEvent->value,
                'field_name' => $field,
            ],
        );
    }

    private function nowIso(): string
    {
        $clock = $this->clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable;

        // DateTime 'c' = ISO-8601 with zone offset, seconds precision. We
        // stay at seconds precision (no u/v) so the jsonl stays diff-friendly
        // and the spec value `ts: ISO-8601 com timezone local` is honoured.
        return $clock()->format('c');
    }
}
