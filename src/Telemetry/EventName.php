<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Telemetry;

/**
 * Closed catalog of telemetry event names (20 canonical + future expansions).
 *
 * Adding a new event name here must be paired with a matching schema entry
 * in {@see FieldAllowlist::SCHEMA}. Tests guarantee the two stay in sync.
 */
enum EventName: string
{
    // Layer 1 — Command lifecycle.
    case CommandStart = 'command.start';
    case CommandEnd = 'command.end';

    // Layer 2 — Install flow (8 events).
    case InstallEnvDetected = 'install.env.detected';
    case InstallPresetSelected = 'install.preset.selected';
    case InstallPhpstanExtensionsSelected = 'install.phpstan_extensions.selected';
    case InstallStubProcessed = 'install.stub.processed';
    case InstallDeptracDetected = 'install.deptrac.detected';
    case InstallDeptracWizardDecision = 'install.deptrac.wizard_decision';
    case InstallCaptainhookActivated = 'install.captainhook.activated';
    case InstallNextStepsRendered = 'install.next_steps.rendered';

    // Layer 3 — Quality gate execution.
    case GateStarted = 'gate.started';
    case GateEnded = 'gate.ended';

    // Layer 4 — Git hook lifecycle.
    case HookTriggered = 'hook.triggered';
    case HookCompleted = 'hook.completed';

    // Layer 5 — Test suite runner.
    case TestStarted = 'test.started';
    case TestEnded = 'test.ended';

    // Layer 6 — Analyze + Baseline commands.
    case AnalyzeEnded = 'analyze.ended';
    case BaselineEnded = 'baseline.ended';

    // Layer 7 — Prepare (schema dump) command.
    case PrepareStepEnded = 'prepare.step.ended';

    // Meta — emitted by the recorder itself when a field is dropped.
    case TelemetryDroppedField = 'telemetry.dropped_field';
}
