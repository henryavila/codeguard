<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Telemetry;

use LogicException;

/**
 * Central privacy gate for telemetry extras.
 *
 * Every key and every string value that reaches `.codeguard/telemetry.jsonl`
 * must be registered here. Free-form strings (paths, URLs, emails, commit
 * SHAs, user names) never pass this boundary.
 *
 * Two modes:
 *  - `strictMode=true` (default, tests + dev): violations throw LogicException
 *    so the offending caller is caught during development.
 *  - `strictMode=false` (prod opt-in): violations silently drop offending
 *    fields from the returned array; the Recorder observes the diff and
 *    emits a `telemetry.dropped_field` meta event (wiring lives in #12).
 *
 * Reserved top-level keys `ts`, `event`, `status`, `duration_ms` are
 * forbidden inside $extras — they would collide with {@see Event::toArray()}.
 */
final class FieldAllowlist
{
    /**
     * Reserved keys owned by {@see Event}; never allowed in extras.
     *
     * @var list<string>
     */
    private const RESERVED_KEYS = ['ts', 'event', 'status', 'duration_ms'];

    /**
     * Validator specs:
     *   ['int']                          — any int
     *   ['int_range', min, max]          — int in closed interval
     *   ['bool']                         — any bool
     *   ['enum',   [...closed values]]   — string in closed set
     *   ['enum_nullable', [...values]]   — string in closed set OR null
     *   ['list_enum', [...closed values]] — list<string> each in closed set
     *
     * Keys here are EventName backing values; inner keys are extras field names.
     *
     * @var array<string, array<string, array{0: string, 1?: mixed, 2?: mixed}>>
     */
    private const SCHEMA = [
        'command.start' => [
            'command' => ['enum', ['install', 'check', 'test', 'prepare', 'analyze', 'baseline', 'telemetry']],
            'preset_flag' => ['enum_nullable', ['default', 'full', 'codeguard', 'codeguard-full']],
        ],
        'command.end' => [
            'command' => ['enum', ['install', 'check', 'test', 'prepare', 'analyze', 'baseline', 'telemetry']],
            'exit_code' => ['int_range', 0, 255],
        ],
        'install.env.detected' => [
            'php_version_major_minor' => ['enum', ['8.3', '8.4', '8.5', 'other']],
            // Widened to [1, 3] so Composer 3 (shipping in preview) records
            // honestly instead of getting silently bucketed into 2.
            'composer_version_major' => ['int_range', 1, 3],
            'has_node' => ['bool'],
            'has_captainhook_binary' => ['bool'],
        ],
        'install.preset.selected' => [
            'preset' => ['enum', ['default', 'full', 'codeguard', 'codeguard-full']],
            'source' => ['enum', ['auto', 'flag', 'prompt']],
        ],
        'install.phpstan_extensions.selected' => [
            'count' => ['int'],
            'enum_values' => ['list_enum', ['larastan', 'phpunit', 'cognitive-complexity', 'dead-code', 'disallowed-calls', 'test-quality']],
        ],
        'install.stub.processed' => [
            'stub_name' => ['enum', ['pint', 'phpstan', 'phpstan-test-quality', 'deptrac', 'infection', 'captainhook', 'jscpd', 'test-quality-test']],
            'stub_outcome' => ['enum', ['created', 'unchanged', 'overwritten', 'kept_custom', 'skipped']],
            'diff_lines_added' => ['int'],
            'diff_lines_removed' => ['int'],
        ],
        'install.deptrac.detected' => [
            'namespace_count' => ['int'],
            'auto_classified_count' => ['int'],
            'auto_skip_count' => ['int'],
            'unclassified_count' => ['int'],
        ],
        'install.deptrac.wizard_decision' => [
            'layer_assigned' => ['enum', ['Domain', 'Application', 'Presentation', 'Infrastructure', 'Skip', 'Custom']],
            'was_saved_choice' => ['bool'],
        ],
        'install.captainhook.activated' => [
            'activation_status' => ['enum', ['installed', 'skipped', 'failed']],
        ],
        'install.next_steps.rendered' => [
            'count' => ['int'],
        ],
        'gate.started' => [
            'gate' => ['enum', ['pint', 'phpstan', 'deptrac', 'infection', 'jscpd', 'insights', 'test_quality', 'audit']],
            'context' => ['enum', ['pre-commit', 'pre-push', 'ci', 'manual']],
        ],
        'gate.ended' => [
            'gate' => ['enum', ['pint', 'phpstan', 'deptrac', 'infection', 'jscpd', 'insights', 'test_quality', 'audit']],
            'context' => ['enum', ['pre-commit', 'pre-push', 'ci', 'manual']],
            'violations_count' => ['int'],
            'files_scanned_count' => ['int'],
        ],
        'hook.triggered' => [
            'hook_type' => ['enum', ['pre-commit', 'commit-msg', 'pre-push', 'post-checkout']],
            'action_count' => ['int'],
        ],
        'hook.completed' => [
            'hook_type' => ['enum', ['pre-commit', 'commit-msg', 'pre-push', 'post-checkout']],
            'failed_action_count' => ['int'],
        ],
        'test.started' => [
            'context' => ['enum', ['manual', 'ci', 'pre-push']],
            'with_coverage' => ['bool'],
        ],
        'test.ended' => [
            'pass_count' => ['int'],
            'fail_count' => ['int'],
            'skip_count' => ['int'],
            'coverage_percent' => ['int_range', -1, 100],
        ],
        'analyze.ended' => [
            'patterns_checked_count' => ['int'],
            'matches_count' => ['int'],
        ],
        'baseline.ended' => [
            'tool' => ['enum', ['phpstan', 'deptrac']],
            'entries_saved_count' => ['int'],
        ],
        'prepare.step.ended' => [
            'step_name' => ['enum', ['dump_schema', 'hash_check', 'migrations_run', 'seed']],
            'connection' => ['enum', ['sqlite', 'mysql', 'pgsql', 'sqlsrv']],
        ],
        // Meta event — both extras values come from the allowlist itself.
        // `target_event` is renamed from spec's `event` to avoid collision
        // with the top-level Event::$event key in toArray().
        'telemetry.dropped_field' => [
            'target_event' => ['enum', [
                'command.start', 'command.end',
                'install.env.detected', 'install.preset.selected',
                'install.phpstan_extensions.selected', 'install.stub.processed',
                'install.deptrac.detected', 'install.deptrac.wizard_decision',
                'install.captainhook.activated', 'install.next_steps.rendered',
                'gate.started', 'gate.ended',
                'hook.triggered', 'hook.completed',
                'test.started', 'test.ended',
                'analyze.ended', 'baseline.ended',
                'prepare.step.ended',
                'telemetry.dropped_field',
            ]],
            // field_name uses the full union of allowlisted keys — see allFieldNames()
            'field_name' => ['field_name'],
        ],
    ];

    public function __construct(
        public readonly bool $strictMode = true,
    ) {}

    /**
     * Validate extras against the schema for $event.
     *
     * @param  array<string, mixed>  $extras
     * @return array<string, mixed> normalised extras (same shape, dropped invalids)
     */
    public function validate(EventName $event, array $extras): array
    {
        $this->ensureNoReservedKeys($extras);

        $schema = self::SCHEMA[$event->value] ?? [];
        $normalised = [];

        foreach ($extras as $key => $value) {
            if (! is_string($key)) {
                $this->fail("non-string key in extras for event '{$event->value}'");

                continue;
            }

            if (! array_key_exists($key, $schema)) {
                $this->fail("field '{$key}' not allowed for event '{$event->value}'");

                continue;
            }

            if (! $this->matchesSpec($value, $schema[$key])) {
                $this->fail("field '{$key}' has invalid value for event '{$event->value}'");

                continue;
            }

            $normalised[$key] = $value;
        }

        return $normalised;
    }

    /**
     * Hard privacy check — throws if any string value is not a known allowlisted
     * value. Used by tests + strict dev paths before serialization.
     *
     * @param  array<string, mixed>  $extras
     */
    public function rejectFreeformStrings(array $extras): void
    {
        $allowed = $this->allAllowedStrings();

        foreach ($extras as $key => $value) {
            if (is_string($value) && ! in_array($value, $allowed, true)) {
                throw new LogicException(
                    "Freeform string rejected in field '".(is_string($key) ? $key : '?')."'"
                );
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_string($item) && ! in_array($item, $allowed, true)) {
                        throw new LogicException(
                            "Freeform string rejected in list field '".(is_string($key) ? $key : '?')."'"
                        );
                    }
                }
            }
        }
    }

    /**
     * Union of every field name declared anywhere in SCHEMA. Serves as the
     * closed set for `telemetry.dropped_field.field_name`.
     *
     * @return list<string>
     */
    public static function allFieldNames(): array
    {
        $names = [];
        foreach (self::SCHEMA as $fields) {
            foreach (array_keys($fields) as $field) {
                $names[$field] = true;
            }
        }

        /** @var list<string> */
        return array_keys($names);
    }

    /**
     * @param  array<string, mixed>  $extras
     */
    private function ensureNoReservedKeys(array $extras): void
    {
        foreach (self::RESERVED_KEYS as $reserved) {
            if (array_key_exists($reserved, $extras)) {
                // Always throw — reserved collisions corrupt the event envelope
                // regardless of strict_mode.
                throw new LogicException(
                    "Reserved key '{$reserved}' cannot appear in extras"
                );
            }
        }
    }

    /**
     * @param  array{0: string, 1?: mixed, 2?: mixed}  $spec
     */
    private function matchesSpec(mixed $value, array $spec): bool
    {
        return match ($spec[0]) {
            'int' => is_int($value),
            'int_range' => is_int($value) && $value >= $spec[1] && $value <= $spec[2],
            'bool' => is_bool($value),
            'enum' => is_string($value) && in_array($value, $spec[1], true),
            'enum_nullable' => $value === null || (is_string($value) && in_array($value, $spec[1], true)),
            'list_enum' => $this->isListOfEnum($value, $spec[1]),
            'field_name' => is_string($value) && in_array($value, self::allFieldNames(), true),
            default => false,
        };
    }

    /**
     * @param  list<string>  $allowed
     */
    private function isListOfEnum(mixed $value, array $allowed): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private function fail(string $reason): void
    {
        if ($this->strictMode) {
            throw new LogicException($reason);
        }
    }

    /**
     * Flattened list of every string literal allowed anywhere in the schema,
     * plus field names (used by `telemetry.dropped_field.field_name`).
     *
     * @return list<string>
     */
    private function allAllowedStrings(): array
    {
        $seen = [];
        foreach (self::SCHEMA as $fields) {
            foreach ($fields as $spec) {
                $kind = $spec[0];
                if ($kind === 'enum' || $kind === 'enum_nullable' || $kind === 'list_enum') {
                    /** @var list<string> $values */
                    $values = $spec[1];
                    foreach ($values as $v) {
                        $seen[$v] = true;
                    }
                }
            }
        }
        foreach (self::allFieldNames() as $field) {
            $seen[$field] = true;
        }

        /** @var list<string> */
        return array_keys($seen);
    }
}
