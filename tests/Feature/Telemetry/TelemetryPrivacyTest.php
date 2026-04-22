<?php

declare(strict_types=1);

use Henryavila\Codeguard\Telemetry\ConfigGate;
use Henryavila\Codeguard\Telemetry\EventName;
use Henryavila\Codeguard\Telemetry\EventStatus;
use Henryavila\Codeguard\Telemetry\FieldAllowlist;
use Henryavila\Codeguard\Telemetry\JsonlWriter;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Telemetry\Rotator;
use PHPUnit\Framework\Assert;

/**
 * Privacy regression tripwire for the telemetry pipeline.
 *
 * Emits a representative event for every layer (1..7 + meta), then sweeps
 * the resulting .jsonl for substrings and regexes that would indicate a
 * PII leak: filesystem paths, email addresses, SHA-1 commit hashes, URLs,
 * or any string not found in FieldAllowlist's closed sets.
 *
 * Designed to catch mistakes in #15's instrumentation work: if someone
 * accidentally forwards $file->getPath() or git author email as an extras
 * value, this test fails loudly.
 */
beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-privacy-'.uniqid();
    mkdir($this->tempDir, 0o755, recursive: true);
    $this->jsonlPath = $this->tempDir.DIRECTORY_SEPARATOR.'telemetry.jsonl';
});

afterEach(function (): void {
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        foreach (scandir($this->tempDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            @unlink($this->tempDir.DIRECTORY_SEPARATOR.$entry);
        }
        @rmdir($this->tempDir);
    }
});

function privacyMakeRecorder(string $path, bool $strict = false): Recorder
{
    return new Recorder(
        gate: new ConfigGate(enabled: true),
        allowlist: new FieldAllowlist(strictMode: $strict),
        rotator: new Rotator,
        writer: new JsonlWriter,
        activePath: $path,
    );
}

/**
 * @return list<string>
 */
function privacyReadLines(string $path): array
{
    if (! file_exists($path)) {
        return [];
    }

    /** @var list<string> $lines */
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return $lines;
}

// Regex-based PII detectors. Each returns null on pass, or the offending
// substring on first hit. Keep these explicit — they are the invariants.
function privacyHits(string $line): array
{
    $hits = [];

    foreach ([
        '/home/' => 'unix-home-path',
        '/Users/' => 'mac-home-path',
    ] as $needle => $label) {
        if (str_contains($line, $needle)) {
            $hits[] = $label.' → '.$needle;
        }
    }

    if (preg_match('/[A-Z]:\\\\/i', $line) === 1) {
        $hits[] = 'windows-drive-letter';
    }

    if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $line, $m) === 1) {
        $hits[] = 'email → '.$m[0];
    }

    if (preg_match('/\b[a-f0-9]{40}\b/', $line, $m) === 1) {
        $hits[] = 'sha1-hash → '.$m[0];
    }

    if (preg_match('#https?://#i', $line) === 1) {
        $hits[] = 'url-scheme';
    }

    return $hits;
}

// -----------------------------------------------------------------------------
// Canonical happy path — all 7 layers emit legitimate extras only.
// -----------------------------------------------------------------------------

it('writes zero PII when every layer emits legitimate extras', function (): void {
    $recorder = privacyMakeRecorder($this->jsonlPath);

    $recorder->record(EventName::CommandStart, EventStatus::Ok, 0, ['command' => 'install', 'preset_flag' => 'default']);
    $recorder->record(EventName::InstallEnvDetected, EventStatus::Ok, 2, ['php_version_major_minor' => '8.3', 'composer_version_major' => 2, 'has_node' => false, 'has_captainhook_binary' => true]);
    $recorder->record(EventName::InstallPresetSelected, EventStatus::Ok, 1, ['preset' => 'codeguard', 'source' => 'auto']);
    $recorder->record(EventName::InstallPhpstanExtensionsSelected, EventStatus::Ok, 5, ['count' => 3, 'enum_values' => ['larastan', 'phpunit', 'dead-code']]);
    $recorder->record(EventName::InstallStubProcessed, EventStatus::Ok, 3, ['stub_name' => 'pint', 'stub_outcome' => 'created', 'diff_lines_added' => 12, 'diff_lines_removed' => 0]);
    $recorder->record(EventName::InstallCaptainhookActivated, EventStatus::Ok, 50, ['activation_status' => 'installed']);
    $recorder->record(EventName::GateStarted, EventStatus::Ok, 0, ['gate' => 'phpstan', 'context' => 'pre-commit']);
    $recorder->record(EventName::GateEnded, EventStatus::Ok, 4230, ['gate' => 'phpstan', 'context' => 'pre-commit', 'violations_count' => 0, 'files_scanned_count' => 3]);
    $recorder->record(EventName::HookTriggered, EventStatus::Ok, 0, ['hook_type' => 'pre-commit', 'action_count' => 2]);
    $recorder->record(EventName::HookCompleted, EventStatus::Ok, 4500, ['hook_type' => 'pre-commit', 'failed_action_count' => 0]);
    $recorder->record(EventName::TestStarted, EventStatus::Ok, 0, ['context' => 'manual', 'with_coverage' => false]);
    $recorder->record(EventName::TestEnded, EventStatus::Ok, 12000, ['pass_count' => 207, 'fail_count' => 0, 'skip_count' => 0, 'coverage_percent' => -1]);
    $recorder->record(EventName::AnalyzeEnded, EventStatus::Ok, 800, ['patterns_checked_count' => 28, 'matches_count' => 3]);
    $recorder->record(EventName::BaselineEnded, EventStatus::Ok, 200, ['tool' => 'phpstan', 'entries_saved_count' => 42]);
    $recorder->record(EventName::PrepareStepEnded, EventStatus::Ok, 500, ['step_name' => 'dump_schema', 'connection' => 'sqlite']);
    $recorder->record(EventName::CommandEnd, EventStatus::Ok, 20000, ['command' => 'install', 'exit_code' => 0]);

    foreach (privacyReadLines($this->jsonlPath) as $line) {
        $hits = privacyHits($line);
        expect($hits)->toBe([], 'PII leaked in line: '.$line.' → '.implode(', ', $hits));
    }
})->group('privacy');

// -----------------------------------------------------------------------------
// Hostile extras — every attempt to inject PII must be dropped or rejected.
// -----------------------------------------------------------------------------

it('drops filesystem paths injected as extras values in non-strict mode', function (): void {
    $recorder = privacyMakeRecorder($this->jsonlPath, strict: false);

    $recorder->record(EventName::CommandStart, EventStatus::Ok, 0, [
        'command' => 'install',
        'rogue_unix_path' => '/home/henry/project/.codeguard',
        'rogue_mac_path' => '/Users/henry/project',
        'rogue_windows_path' => 'C:\\Users\\henry\\project',
    ]);

    foreach (privacyReadLines($this->jsonlPath) as $line) {
        expect(privacyHits($line))->toBe([], 'path leaked: '.$line);
    }
})->group('privacy');

it('drops email and git SHA extras values in non-strict mode', function (): void {
    $recorder = privacyMakeRecorder($this->jsonlPath, strict: false);

    $recorder->record(EventName::CommandEnd, EventStatus::Ok, 0, [
        'command' => 'install',
        'exit_code' => 0,
        'author_email' => 'personal@henryavila.com',
        'commit_sha' => '7e0326cfa812d3b6e2f8a00df1c9ab3de4b1c6f5',
    ]);

    foreach (privacyReadLines($this->jsonlPath) as $line) {
        expect(privacyHits($line))->toBe([], 'PII leaked: '.$line);
    }
})->group('privacy');

it('drops URL-valued extras in non-strict mode', function (): void {
    $recorder = privacyMakeRecorder($this->jsonlPath, strict: false);

    $recorder->record(EventName::GateStarted, EventStatus::Ok, 0, [
        'gate' => 'phpstan',
        'context' => 'pre-commit',
        'repo_url' => 'https://github.com/henry/codeguard',
    ]);

    foreach (privacyReadLines($this->jsonlPath) as $line) {
        expect(privacyHits($line))->toBe([], 'URL leaked: '.$line);
    }
})->group('privacy');

it('strict mode refuses to write events with any PII extras', function (): void {
    $recorder = privacyMakeRecorder($this->jsonlPath, strict: true);

    $recorder->record(EventName::CommandStart, EventStatus::Ok, 0, [
        'command' => 'install',
        'rogue_path' => '/home/henry/leak',
    ]);

    // The strict allowlist throws internally; Recorder swallows → no line
    // written at all. Safer than a partial write with stripped fields.
    expect(file_exists($this->jsonlPath))->toBeFalse();
})->group('privacy');

// -----------------------------------------------------------------------------
// Every string value that does land in the jsonl must come from the
// allowlist (timezone, event names, status tokens, enum values).
// -----------------------------------------------------------------------------

it('every string value in the jsonl is either a timestamp or an allowlisted literal', function (): void {
    $recorder = privacyMakeRecorder($this->jsonlPath);

    $recorder->record(EventName::CommandStart, EventStatus::Ok, 0, ['command' => 'check', 'preset_flag' => null]);
    $recorder->record(EventName::InstallDeptracWizardDecision, EventStatus::Ok, 4, ['layer_assigned' => 'Domain', 'was_saved_choice' => true]);
    $recorder->record(EventName::InstallNextStepsRendered, EventStatus::Ok, 0, ['count' => 4]);
    $recorder->record(EventName::TelemetryDroppedField, EventStatus::Skip, 0, ['target_event' => 'gate.ended', 'field_name' => 'violations_count']);

    $allowedStrings = FieldAllowlist::allFieldNames();
    // Also collect every enum value from the schema (via reflection on a fresh allowlist).
    $allowlist = new FieldAllowlist;
    $reflection = new ReflectionClass($allowlist);
    $schemaConst = $reflection->getConstant('SCHEMA');
    foreach ($schemaConst as $fields) {
        foreach ($fields as $spec) {
            if (in_array($spec[0], ['enum', 'enum_nullable', 'list_enum'], true)) {
                $allowedStrings = [...$allowedStrings, ...$spec[1]];
            }
        }
    }
    // Plus the top-level envelope string values: EventName backing values + EventStatus tokens.
    foreach (EventName::cases() as $case) {
        $allowedStrings[] = $case->value;
    }
    $allowedStrings = [...$allowedStrings, 'ok', 'fail', 'skip'];

    foreach (privacyReadLines($this->jsonlPath) as $rawLine) {
        $decoded = json_decode($rawLine, true);
        expect($decoded)->toBeArray();

        foreach ($decoded as $key => $value) {
            if ($key === 'ts') {
                expect($value)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', "ts malformed: $value");

                continue;
            }

            if (! is_string($value)) {
                continue; // ints and bools are already safe
            }

            // Pest's toContain/toBeTrue don't accept a custom failure message,
            // so we reach for PHPUnit's assertTrue directly — the message tells
            // the future reader exactly which key leaked which string.
            Assert::assertTrue(
                in_array($value, $allowedStrings, true),
                "freeform string reached jsonl: key=$key value=$value",
            );
        }
    }
})->group('privacy');
