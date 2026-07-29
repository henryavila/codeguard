<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Mode
    |--------------------------------------------------------------------------
    |
    | CodeGuard runtime mode. Controls which environment the package is running
    | in. Can be overridden via CODEGUARD_MODE env var.
    |
    | Supported: "default", "ci", "dev", "debug"
    |
    */

    'mode' => env('CODEGUARD_MODE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Preset
    |--------------------------------------------------------------------------
    |
    | Which quality gate preset is active. Auto-selected by `codeguard:install`
    | based on Node.js presence, but can be overridden here.
    |
    | - "codeguard"       Pint + PHPStan + Deptrac + Infection + CaptainHook
    |                     (PHP-native, no Node required)
    |
    | - "codeguard-full"  + jscpd + Insights + TestQualityTest
    |                     (requires Node.js for jscpd)
    |
    */

    'preset' => env('CODEGUARD_PRESET', 'codeguard'),

    /*
    |--------------------------------------------------------------------------
    | Quality Gates
    |--------------------------------------------------------------------------
    |
    | Each gate is a quality tool run via `codeguard:check`. Gates run
    | sequentially with fail-fast semantics. Disable unused gates to speed
    | up CI. `command` is shell-expanded so any binary path works.
    |
    */

    'gates' => [
        'audit' => [
            'enabled' => true,
            'command' => 'composer audit --format=plain',
            'description' => 'Composer security audit',
        ],
        'pint' => [
            'enabled' => true,
            'command' => './vendor/bin/pint --test',
            'description' => 'Laravel Pint (code style check)',
        ],
        'phpstan' => [
            'enabled' => true,
            // --memory-limit=2G keeps larger Laravel apps from OOM'ing
            // mid-analysis. Arch-sized projects (40k+ LOC) need at least 2G.
            'command' => './vendor/bin/phpstan analyse --memory-limit=2G --no-progress',
            'description' => 'PHPStan static analysis',
        ],
        'deptrac' => [
            'enabled' => true,
            'command' => './vendor/bin/deptrac analyse --no-progress',
            'description' => 'Deptrac architecture boundaries',
        ],
        'infection' => [
            'enabled' => true,
            // --show-mutations accepts integer or 'max'; it does not accept
            // 'false'. Omitting the flag entirely is how you keep the output
            // concise (no per-mutant detail).
            'command' => './vendor/bin/infection --min-msi=60 --min-covered-msi=70 --no-progress',
            'description' => 'Infection mutation testing',
        ],
        'jscpd' => [
            'enabled' => false,
            'command' => 'npx jscpd --reporters console --threshold 3',
            'description' => 'Code duplication detection (requires Node.js)',
        ],
        'insights' => [
            'enabled' => false,
            'command' => 'php artisan insights --no-interaction --summary',
            'description' => 'PHP Insights metrics',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Test Stages
    |--------------------------------------------------------------------------
    |
    | Multi-stage test runner configuration. Each stage runs a specific test
    | suite (unit, feature, integration, etc) with its own env vars and
    | report format.
    |
    */

    'stages' => [
        'unit' => [
            'enabled' => true,
            'label' => 'Unit Tests',
            'phase' => 1,
            'description' => 'Pure unit tests — no framework boot, no database',
            'command' => ['./vendor/bin/pest', '--testsuite=Unit'],
            'env' => [],
            'report_type' => 'junit',
            'report_file' => 'unit.xml',
            'report_arg_prefix' => '--log-junit=',
            'fast_fail_arguments' => ['--bail'],
        ],
        'feature' => [
            'enabled' => true,
            'label' => 'Feature Tests',
            'phase' => 1,
            'description' => 'HTTP + Artisan command tests booted via Testbench',
            'command' => ['./vendor/bin/pest', '--testsuite=Feature'],
            'env' => [],
            'report_type' => 'junit',
            'report_file' => 'feature.xml',
            'report_arg_prefix' => '--log-junit=',
            'fast_fail_arguments' => ['--bail'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Report Directory
    |--------------------------------------------------------------------------
    |
    | Where test and gate reports are written. Defaults to Laravel's
    | storage path so reports are gitignored by default.
    |
    */

    'report_dir' => storage_path('framework/testing/test-reports'),

    /*
    |--------------------------------------------------------------------------
    | Prepare (Schema Dump)
    |--------------------------------------------------------------------------
    |
    | Configuration for `codeguard:prepare` — multi-driver schema dump with
    | hash cache. Handles native Laravel drivers (MySQL, PostgreSQL, SQLite),
    | SQL Server fallback, and in-memory SQLite via PDO export.
    |
    */

    'prepare' => [
        'connection' => env('CODEGUARD_PREPARE_CONNECTION', env('DB_CONNECTION', 'sqlite')),
        'dump_path' => database_path('schema/dump.sql'),
        'hash_path' => database_path('schema/.dump-hash'),
        'migrations_path' => database_path('migrations'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Protected Configs
    |--------------------------------------------------------------------------
    |
    | Configuration files protected from AI agent edits via Claude hooks
    | plugin (henryavila/codeguard-hooks). Edits to these paths trigger
    | a nudge asking the user to confirm.
    |
    | Note: enforcement is best-effort (not hard) due to Claude Code
    | bypass limitations. CI is the real gate.
    |
    */

    'protected_configs' => [
        'phpstan.neon',
        'phpstan-baseline.neon',
        'pint.json',
        'deptrac.yaml',
        'deptrac-baseline.yaml',
        'psalm.xml',
        'infection.json5',
        'phpunit.xml',
        '.jscpd.json',
        'captainhook.json',
    ],

    /*
    |--------------------------------------------------------------------------
    | Patterns
    |--------------------------------------------------------------------------
    |
    | Pattern-based LLM review configuration. Presets map to YAML bundles
    | shipped in `resources/patterns/`. Custom project patterns are
    | auto-discovered in `base_path('.codeguard/patterns')`.
    |
    */

    'patterns' => [
        'enabled_presets' => ['core', 'php', 'php-laravel'],
        'custom_paths' => [
            // Auto-discovery includes base_path('.codeguard/patterns').
            // Add additional paths here for monorepo/shared patterns:
            // '/path/to/shared/patterns',
        ],
        'baseline_path' => base_path('.codeguard/baseline.json'),

        /*
        | Pattern focus for codeguard:analyze (overridable via --focus).
        | - "full"       All enabled presets (hygiene + G3).
        | - "contractor" G3 contractor-gate only: R4 security/data + architecture
        |                + service-layer. Default critique floor becomes 4.
        */
        'focus' => env('CODEGUARD_ANALYZE_FOCUS', 'full'),

        /*
        | Drop findings whose critique verified_score is below this (0–10).
        | Uncritiqued findings (no verified_score) are always kept.
        | null = use focus default (full → 1 / drop only 0; contractor → 4).
        | CLI: --min-critique-score=
        */
        'min_critique_score' => env('CODEGUARD_MIN_CRITIQUE_SCORE'),

        /*
        | Optional override of the built-in contractor key set. Empty = package default
        | (mass-assignment, raw-sql-injection, missing-authorization, N+1, transaction,
        | unbounded-query, layer-dependency-direction, bounded-contexts,
        | no-circular-dependencies, service-layer).
        */
        'contractor_keys' => [],

        /*
        | When focus=full, hygiene patterns (type-declarations, dry, …) are
        | excluded by default. Set true or pass --include-hygiene to restore
        | the full inventory (types, small-functions, few-arguments, …).
        */
        'include_hygiene' => env('CODEGUARD_INCLUDE_HYGIENE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Rules
    |--------------------------------------------------------------------------
    |
    | Configure which AI coding tools receive generated rules. During
    | `codeguard:install`, selected tools get their config files written
    | from canonical markdown in `resources/rules/`.
    |
    */

    'ai_rules' => [
        'targets' => [
            'claude' => true,   // .claude/rules/*.md + CLAUDE.md
            'cursor' => true,   // .cursor/rules/*.mdc
            'copilot' => true,  // .github/copilot-instructions.md
            'agents_md' => true, // AGENTS.md (universal)
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry
    |--------------------------------------------------------------------------
    |
    | Local-only telemetry — every event is appended to `.codeguard/telemetry.jsonl`
    | in the consumer's project. Nothing is uploaded. See spec §5 for the full
    | event catalog. Tooling:
    |
    |   php artisan codeguard:telemetry:enable   # turn on
    |   php artisan codeguard:telemetry:disable  # turn off
    |   php artisan codeguard:telemetry:clear    # delete all recorded jsonl
    |
    | `strict_mode=true` makes FieldAllowlist throw on any schema violation;
    | `false` silently drops the offending field and emits a meta event
    | `telemetry.dropped_field` so developers can investigate later.
    |
    */

    'telemetry' => [
        'enabled' => env('CODEGUARD_TELEMETRY_ENABLED', false),
        // Default false for production consumers: a mis-mapped extras
        // field should drop silently (and emit a `telemetry.dropped_field`
        // meta event) rather than discard the whole envelope. Dev/test
        // environments should opt-in to strict=true in their phpunit.xml
        // or .env.testing so schema violations surface loud.
        'strict_mode' => env('CODEGUARD_TELEMETRY_STRICT', false),
        'path' => '.codeguard'.DIRECTORY_SEPARATOR.'telemetry.jsonl',
        'rotate_bytes' => 10 * 1024 * 1024,
        'retain_archives' => 5,
    ],

];
