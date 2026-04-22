<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Telemetry;

/**
 * Immutable value object representing a single telemetry event line.
 *
 * Emission format is one JSON object per line (.jsonl). Key ordering in
 * {@see self::toArray()} is stable so snapshot-style tests don't fight
 * PHP's insertion-order semantics.
 *
 * Reserved top-level keys (`ts`, `event`, `status`, `duration_ms`) MUST NOT
 * be reused by $extras; {@see FieldAllowlist::validate()} rejects that shape.
 */
final readonly class Event
{
    /**
     * @param  array<string, mixed>  $extras  validated + normalised by FieldAllowlist
     */
    public function __construct(
        public string $ts,
        public EventName $event,
        public EventStatus $status,
        public int $durationMs,
        public array $extras = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ts' => $this->ts,
            'event' => $this->event->value,
            'status' => $this->status->value,
            'duration_ms' => $this->durationMs,
            ...$this->extras,
        ];
    }
}
