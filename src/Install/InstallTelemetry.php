<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Henryavila\Codeguard\Telemetry\EventName;
use Henryavila\Codeguard\Telemetry\EventStatus;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Testing\Preset;

/**
 * Façade that lifts the Telemetry emission schema into install-flow
 * vocabulary: `commandStarted`, `envDetected`, `presetSelected`, etc.
 *
 * Isolates every `Recorder::record()` call in one file so the security
 * reviewer only has to audit a small surface for privacy invariants
 * (spec §5.4). The main command stays readable: one injected dep, a
 * handful of typed call sites.
 *
 * This class owns all value-to-enum mapping for the install layer
 * (e.g. "8.3.0" → "8.3", `CaptainhookInstallStatus::BinaryMissing` → "failed").
 * Mappings that silently fall back to a sentinel (`other`, `failed`) do
 * so only when the input falls outside the spec catalog — which keeps
 * the jsonl inside FieldAllowlist's closed sets by construction.
 */
final class InstallTelemetry
{
    /** @var list<string> */
    private const ALLOWED_PRESET_FLAGS = ['default', 'full', 'codeguard', 'codeguard-full'];

    public function __construct(
        private readonly Recorder $recorder,
    ) {}

    public function commandStarted(?string $presetFlag): void
    {
        $this->recorder->record(
            event: EventName::CommandStart,
            status: EventStatus::Ok,
            durationMs: 0,
            extras: [
                'command' => 'install',
                'preset_flag' => $this->normalisePresetFlag($presetFlag),
            ],
        );
    }

    public function commandEnded(int $exitCode, int $durationMs = 0): void
    {
        $status = $exitCode === 0 ? EventStatus::Ok : EventStatus::Fail;

        $this->recorder->record(
            event: EventName::CommandEnd,
            status: $status,
            durationMs: $durationMs,
            extras: [
                'command' => 'install',
                'exit_code' => max(0, min(255, $exitCode)),
            ],
        );
    }

    public function envDetected(EnvironmentInfo $env): void
    {
        $this->recorder->record(
            event: EventName::InstallEnvDetected,
            status: EventStatus::Ok,
            durationMs: 0,
            extras: [
                'php_version_major_minor' => $this->phpVersionBucket($env->phpVersion),
                'composer_version_major' => $this->composerMajor($env->composerVersion),
                'has_node' => $env->hasNode(),
                'has_captainhook_binary' => $env->hasCaptainhookBinary,
            ],
        );
    }

    public function presetSelected(Preset $preset, string $source): void
    {
        $this->recorder->record(
            event: EventName::InstallPresetSelected,
            status: EventStatus::Ok,
            durationMs: 0,
            extras: [
                'preset' => $preset->value,
                'source' => in_array($source, ['auto', 'flag', 'prompt'], true) ? $source : 'auto',
            ],
        );
    }

    /**
     * @param  list<PhpstanExtension>  $extensions
     */
    public function phpstanExtensionsSelected(array $extensions): void
    {
        $this->recorder->record(
            event: EventName::InstallPhpstanExtensionsSelected,
            status: EventStatus::Ok,
            durationMs: 0,
            extras: [
                'count' => count($extensions),
                'enum_values' => array_map(
                    static fn (PhpstanExtension $e): string => $e->value,
                    $extensions,
                ),
            ],
        );
    }

    public function captainhookActivated(CaptainhookInstallResult $result): void
    {
        $this->recorder->record(
            event: EventName::InstallCaptainhookActivated,
            status: $this->statusFromCaptainhook($result->status),
            durationMs: 0,
            extras: [
                'activation_status' => $this->activationFromCaptainhook($result->status),
            ],
        );
    }

    public function nextStepsRendered(int $count): void
    {
        $this->recorder->record(
            event: EventName::InstallNextStepsRendered,
            status: EventStatus::Ok,
            durationMs: 0,
            extras: [
                'count' => max(0, $count),
            ],
        );
    }

    private function normalisePresetFlag(?string $flag): ?string
    {
        if ($flag === null || $flag === '') {
            return null;
        }

        return in_array($flag, self::ALLOWED_PRESET_FLAGS, true) ? $flag : null;
    }

    private function phpVersionBucket(string $version): string
    {
        if (preg_match('/^(\d+\.\d+)/', $version, $match) !== 1) {
            return 'other';
        }

        return match ($match[1]) {
            '8.3', '8.4', '8.5' => $match[1],
            default => 'other',
        };
    }

    private function composerMajor(string $version): int
    {
        if (preg_match('/^(\d+)/', $version, $match) !== 1) {
            return 2;
        }

        $major = (int) $match[1];

        return in_array($major, [1, 2], true) ? $major : 2;
    }

    private function activationFromCaptainhook(CaptainhookInstallStatus $status): string
    {
        return match ($status) {
            CaptainhookInstallStatus::Installed => 'installed',
            CaptainhookInstallStatus::Skipped => 'skipped',
            CaptainhookInstallStatus::Failed,
            CaptainhookInstallStatus::BinaryMissing => 'failed',
        };
    }

    private function statusFromCaptainhook(CaptainhookInstallStatus $status): EventStatus
    {
        return match ($status) {
            CaptainhookInstallStatus::Installed => EventStatus::Ok,
            CaptainhookInstallStatus::Skipped => EventStatus::Skip,
            CaptainhookInstallStatus::Failed,
            CaptainhookInstallStatus::BinaryMissing => EventStatus::Fail,
        };
    }
}
