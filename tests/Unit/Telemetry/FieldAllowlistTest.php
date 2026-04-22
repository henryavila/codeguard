<?php

declare(strict_types=1);

use Henryavila\Codeguard\Telemetry\EventName;
use Henryavila\Codeguard\Telemetry\FieldAllowlist;

// -----------------------------------------------------------------------------
// Happy path — every EventName has a valid example payload
// -----------------------------------------------------------------------------

it('accepts well-formed extras for command.start', function (): void {
    $allowlist = new FieldAllowlist;

    $normalised = $allowlist->validate(EventName::CommandStart, [
        'command' => 'install',
        'preset_flag' => 'default',
    ]);

    expect($normalised)->toBe([
        'command' => 'install',
        'preset_flag' => 'default',
    ]);
});

it('accepts null for nullable enum fields', function (): void {
    $allowlist = new FieldAllowlist;

    $normalised = $allowlist->validate(EventName::CommandStart, [
        'command' => 'install',
        'preset_flag' => null,
    ]);

    expect($normalised['preset_flag'])->toBeNull();
});

it('accepts bool, int, and list<enum> fields', function (): void {
    $allowlist = new FieldAllowlist;

    $env = $allowlist->validate(EventName::InstallEnvDetected, [
        'php_version_major_minor' => '8.3',
        'composer_version_major' => 2,
        'has_node' => true,
        'has_captainhook_binary' => false,
    ]);
    expect($env['has_node'])->toBeTrue();

    $ext = $allowlist->validate(EventName::InstallPhpstanExtensionsSelected, [
        'count' => 3,
        'enum_values' => ['larastan', 'phpunit', 'dead-code'],
    ]);
    expect($ext['enum_values'])->toBe(['larastan', 'phpunit', 'dead-code']);
});

it('accepts -1 and 100 as boundary coverage_percent', function (): void {
    $allowlist = new FieldAllowlist;

    $r1 = $allowlist->validate(EventName::TestEnded, [
        'pass_count' => 144,
        'fail_count' => 0,
        'skip_count' => 0,
        'coverage_percent' => -1,
    ]);
    expect($r1['coverage_percent'])->toBe(-1);

    $r2 = $allowlist->validate(EventName::TestEnded, [
        'pass_count' => 144,
        'fail_count' => 0,
        'skip_count' => 0,
        'coverage_percent' => 100,
    ]);
    expect($r2['coverage_percent'])->toBe(100);
});

// -----------------------------------------------------------------------------
// Strict mode — violations throw LogicException
// -----------------------------------------------------------------------------

it('strict mode throws on unknown extra field', function (): void {
    $allowlist = new FieldAllowlist(strictMode: true);

    $allowlist->validate(EventName::CommandStart, [
        'command' => 'install',
        'unexpected_field' => 'whatever',
    ]);
})->throws(LogicException::class, "not allowed for event 'command.start'");

it('strict mode throws on invalid enum value', function (): void {
    $allowlist = new FieldAllowlist(strictMode: true);

    $allowlist->validate(EventName::CommandStart, [
        'command' => 'install',
        'preset_flag' => 'invalid-preset',
    ]);
})->throws(LogicException::class, 'invalid value');

it('strict mode throws when int field gets a string', function (): void {
    $allowlist = new FieldAllowlist(strictMode: true);

    $allowlist->validate(EventName::TestEnded, [
        'pass_count' => '144',
        'fail_count' => 0,
        'skip_count' => 0,
        'coverage_percent' => 80,
    ]);
})->throws(LogicException::class, 'invalid value');

it('strict mode throws when bool field gets an int', function (): void {
    $allowlist = new FieldAllowlist(strictMode: true);

    $allowlist->validate(EventName::InstallEnvDetected, [
        'php_version_major_minor' => '8.3',
        'composer_version_major' => 2,
        'has_node' => 1,
        'has_captainhook_binary' => true,
    ]);
})->throws(LogicException::class, 'invalid value');

it('strict mode throws when list_enum contains unknown value', function (): void {
    $allowlist = new FieldAllowlist(strictMode: true);

    $allowlist->validate(EventName::InstallPhpstanExtensionsSelected, [
        'count' => 1,
        'enum_values' => ['larastan', 'some-custom-extension'],
    ]);
})->throws(LogicException::class, 'invalid value');

it('strict mode throws when list_enum is not actually a list', function (): void {
    $allowlist = new FieldAllowlist(strictMode: true);

    $allowlist->validate(EventName::InstallPhpstanExtensionsSelected, [
        'count' => 1,
        'enum_values' => ['0' => 'larastan', '2' => 'phpunit'], // gaps = not a list
    ]);
})->throws(LogicException::class, 'invalid value');

it('strict mode throws when int is out of range', function (): void {
    $allowlist = new FieldAllowlist(strictMode: true);

    $allowlist->validate(EventName::CommandEnd, [
        'command' => 'install',
        'exit_code' => 256,
    ]);
})->throws(LogicException::class, 'invalid value');

// -----------------------------------------------------------------------------
// Non-strict mode — violations silently drop
// -----------------------------------------------------------------------------

it('non-strict mode drops unknown fields and returns only valid ones', function (): void {
    $allowlist = new FieldAllowlist(strictMode: false);

    $normalised = $allowlist->validate(EventName::CommandStart, [
        'command' => 'install',
        'preset_flag' => 'default',
        'rogue_field' => '/home/henry/secret',
    ]);

    expect($normalised)->toBe([
        'command' => 'install',
        'preset_flag' => 'default',
    ]);
});

it('non-strict mode drops invalid values while keeping valid ones', function (): void {
    $allowlist = new FieldAllowlist(strictMode: false);

    $normalised = $allowlist->validate(EventName::GateEnded, [
        'gate' => 'phpstan',
        'context' => 'pre-commit',
        'violations_count' => 12,
        'files_scanned_count' => 'seven', // invalid type
    ]);

    expect($normalised)->toHaveKeys(['gate', 'context', 'violations_count'])
        ->and($normalised)->not->toHaveKey('files_scanned_count');
});

// -----------------------------------------------------------------------------
// Reserved keys — always throw (regardless of strict_mode)
// -----------------------------------------------------------------------------

it('always throws when extras contains reserved key ts', function (bool $strict): void {
    $allowlist = new FieldAllowlist(strictMode: $strict);

    $allowlist->validate(EventName::CommandStart, [
        'command' => 'install',
        'ts' => '2026-04-22T00:00:00+00:00',
    ]);
})->with([true, false])->throws(LogicException::class, "Reserved key 'ts'");

it('always throws when extras contains reserved key event', function (): void {
    $allowlist = new FieldAllowlist(strictMode: false);
    $allowlist->validate(EventName::CommandStart, [
        'command' => 'install',
        'event' => 'other.name',
    ]);
})->throws(LogicException::class, "Reserved key 'event'");

it('always throws when extras contains reserved key status', function (): void {
    $allowlist = new FieldAllowlist(strictMode: false);
    $allowlist->validate(EventName::CommandStart, [
        'command' => 'install',
        'status' => 'ok',
    ]);
})->throws(LogicException::class, "Reserved key 'status'");

it('always throws when extras contains reserved key duration_ms', function (): void {
    $allowlist = new FieldAllowlist(strictMode: false);
    $allowlist->validate(EventName::CommandStart, [
        'command' => 'install',
        'duration_ms' => 42,
    ]);
})->throws(LogicException::class, "Reserved key 'duration_ms'");

// -----------------------------------------------------------------------------
// rejectFreeformStrings — hard privacy check
// -----------------------------------------------------------------------------

it('rejectFreeformStrings passes when every string is allowlisted', function (): void {
    $allowlist = new FieldAllowlist;

    $allowlist->rejectFreeformStrings([
        'command' => 'install',
        'gate' => 'phpstan',
        'has_node' => true,
        'count' => 3,
    ]);

    expect(true)->toBeTrue(); // reached without throwing
});

it('rejectFreeformStrings throws on filesystem paths', function (string $path): void {
    $allowlist = new FieldAllowlist;
    $allowlist->rejectFreeformStrings(['suspect' => $path]);
})->with([
    '/home/henry/.codeguard',
    '/Users/henry/project',
    'C:\\Users\\henry\\project',
    '/var/log/messages',
])->throws(LogicException::class, 'Freeform string rejected');

it('rejectFreeformStrings throws on email addresses', function (): void {
    $allowlist = new FieldAllowlist;
    $allowlist->rejectFreeformStrings(['suspect' => 'personal@henryavila.com']);
})->throws(LogicException::class, 'Freeform string rejected');

it('rejectFreeformStrings throws on SHA-1 hashes', function (): void {
    $allowlist = new FieldAllowlist;
    $allowlist->rejectFreeformStrings(['suspect' => '7e0326cfa812d3b6e2f8a00df1c9ab3de4b1c6f5']);
})->throws(LogicException::class, 'Freeform string rejected');

it('rejectFreeformStrings throws on URLs', function (): void {
    $allowlist = new FieldAllowlist;
    $allowlist->rejectFreeformStrings(['suspect' => 'https://github.com/henry/codeguard']);
})->throws(LogicException::class, 'Freeform string rejected');

it('rejectFreeformStrings throws on freeform strings inside list values', function (): void {
    $allowlist = new FieldAllowlist;
    $allowlist->rejectFreeformStrings([
        'enum_values' => ['larastan', '/home/henry/.secrets'],
    ]);
})->throws(LogicException::class, 'list field');

it('rejectFreeformStrings does not flag ints or bools', function (): void {
    $allowlist = new FieldAllowlist;
    $allowlist->rejectFreeformStrings([
        'count' => 42,
        'has_node' => true,
        'files_scanned_count' => 0,
    ]);
    expect(true)->toBeTrue();
});

// -----------------------------------------------------------------------------
// Schema completeness + allFieldNames
// -----------------------------------------------------------------------------

it('allFieldNames returns all extras keys declared in the schema', function (): void {
    $names = FieldAllowlist::allFieldNames();

    // Spot-check a handful covering every layer.
    expect($names)->toContain('command', 'preset_flag', 'exit_code')
        ->and($names)->toContain('php_version_major_minor', 'composer_version_major', 'has_node', 'has_captainhook_binary')
        ->and($names)->toContain('stub_name', 'stub_outcome', 'activation_status')
        ->and($names)->toContain('gate', 'context', 'violations_count', 'files_scanned_count')
        ->and($names)->toContain('hook_type', 'action_count', 'failed_action_count')
        ->and($names)->toContain('with_coverage', 'coverage_percent')
        ->and($names)->toContain('tool', 'entries_saved_count')
        ->and($names)->toContain('step_name', 'connection')
        ->and($names)->toContain('field_name');
});

it('allFieldNames entries are all unique', function (): void {
    $names = FieldAllowlist::allFieldNames();
    expect($names)->toBe(array_values(array_unique($names)));
});

it('telemetry.dropped_field validates field_name against allFieldNames', function (): void {
    $allowlist = new FieldAllowlist;

    $normalised = $allowlist->validate(EventName::TelemetryDroppedField, [
        'target_event' => 'gate.ended',
        'field_name' => 'violations_count',
    ]);

    expect($normalised)->toBe([
        'target_event' => 'gate.ended',
        'field_name' => 'violations_count',
    ]);
});

it('telemetry.dropped_field rejects field_name that is not a declared key', function (): void {
    $allowlist = new FieldAllowlist(strictMode: true);

    $allowlist->validate(EventName::TelemetryDroppedField, [
        'target_event' => 'gate.ended',
        'field_name' => 'arbitrary_user_input',
    ]);
})->throws(LogicException::class, 'invalid value');

it('events with zero extras still validate cleanly', function (): void {
    $allowlist = new FieldAllowlist;

    $normalised = $allowlist->validate(EventName::CommandStart, []);

    expect($normalised)->toBe([]);
});
