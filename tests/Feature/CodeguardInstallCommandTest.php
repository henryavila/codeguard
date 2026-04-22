<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\AllowPluginsStatus;
use Henryavila\Codeguard\Install\CaptainhookInstaller;
use Henryavila\Codeguard\Install\CaptainhookInstallResult;
use Henryavila\Codeguard\Install\CaptainhookInstallStatus;
use Henryavila\Codeguard\Install\CodeguardDirectoryInitializer;
use Henryavila\Codeguard\Install\ComposerAllowPluginsCheck;
use Henryavila\Codeguard\Install\EnvironmentDetector;
use Henryavila\Codeguard\Install\EnvironmentInfo;
use Henryavila\Codeguard\Install\InstallTelemetry;
use Henryavila\Codeguard\Install\LegacyStubCleaner;
use Henryavila\Codeguard\Install\StubDiffer;
use Henryavila\Codeguard\Install\StubOverrides;
use Henryavila\Codeguard\Install\StubPublisher;
use Henryavila\Codeguard\Telemetry\ConfigGate;
use Henryavila\Codeguard\Telemetry\FieldAllowlist;
use Henryavila\Codeguard\Telemetry\JsonlWriter;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Telemetry\Rotator;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->tempApp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-install-'.uniqid();
    mkdir($this->tempApp, 0o755, true);

    // Stub out EnvironmentDetector so we don't depend on the host machine
    // having node/composer/captainhook available or in any particular version.
    $fakeEnv = new EnvironmentInfo(
        phpVersion: '8.3.0',
        composerVersion: '2.7.0',
        nodeVersion: null,
        hasPackageJson: false,
        hasNodeModules: false,
        hasCaptainhookBinary: false,
    );

    $this->app->singleton(EnvironmentDetector::class, function () use ($fakeEnv): EnvironmentDetector {
        return new class($fakeEnv) extends EnvironmentDetector
        {
            public function __construct(private readonly EnvironmentInfo $env)
            {
                parent::__construct(new Filesystem, sys_get_temp_dir());
            }

            public function detect(): EnvironmentInfo
            {
                return $this->env;
            }
        };
    });

    // Prevent CaptainHook from shelling out. Default to a happy-path
    // Installed result so the suite exercises the "no pendencies → exit 0"
    // branch; per-test overrides flip this when they want to exercise the
    // summary+exit-2 path.
    $this->app->singleton(CaptainhookInstaller::class, function (): CaptainhookInstaller {
        return new class(sys_get_temp_dir()) extends CaptainhookInstaller
        {
            public function install(EnvironmentInfo $env): CaptainhookInstallResult
            {
                return new CaptainhookInstallResult(
                    status: CaptainhookInstallStatus::Installed,
                    message: 'stubbed — hooks installed',
                );
            }
        };
    });

    // Default stub: the consumer already allows captainhook/hook-installer.
    $this->app->singleton(ComposerAllowPluginsCheck::class, function (): ComposerAllowPluginsCheck {
        return new class(new Filesystem, sys_get_temp_dir().'/unused.json') extends ComposerAllowPluginsCheck
        {
            public function check(string $plugin): AllowPluginsStatus
            {
                return AllowPluginsStatus::Allowed;
            }

            public function allow(string $plugin): bool
            {
                return true;
            }
        };
    });

    // Point the directory initializer at the same tempApp so .codeguard/
    // lands there (and not inside Testbench's fixture basepath).
    $tempApp = $this->tempApp;
    $this->app->singleton(CodeguardDirectoryInitializer::class, function () use ($tempApp): CodeguardDirectoryInitializer {
        return new CodeguardDirectoryInitializer(new Filesystem, $tempApp);
    });

    // Repoint StubPublisher at a writable temp directory instead of
    // Testbench's read-only fixture base path.
    $stubsSourcePath = realpath(__DIR__.'/../../resources/stubs')
        ?: __DIR__.'/../../resources/stubs';

    // Point StubOverrides at the per-test tempApp so overrides persist inside
    // the fixture dir, not inside a globally shared one.
    $this->app->singleton(StubOverrides::class, function () use ($tempApp): StubOverrides {
        return new StubOverrides(
            filesystem: new Filesystem,
            path: $tempApp.'/.codeguard/stub-overrides.yaml',
        );
    });

    // Same rebind for LegacyStubCleaner — by default it walks Testbench's
    // fixture root and never sees the files under $tempApp.
    $this->app->singleton(LegacyStubCleaner::class, function () use ($tempApp): LegacyStubCleaner {
        return new LegacyStubCleaner(
            filesystem: new Filesystem,
            basePath: $tempApp,
        );
    });

    $this->app->singleton(StubPublisher::class, function ($app) use ($stubsSourcePath): StubPublisher {
        return new StubPublisher(
            filesystem: $app->make(Filesystem::class),
            basePath: $this->tempApp,
            stubsSourcePath: $stubsSourcePath,
            differ: $app->make(StubDiffer::class),
            overrides: $app->make(StubOverrides::class),
        );
    });
});

afterEach(function (): void {
    deleteRecursiveFeature($this->tempApp);
});

function deleteRecursiveFeature(string $path): void
{
    if (! file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);

        return;
    }

    $entries = scandir($path) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        deleteRecursiveFeature($path.DIRECTORY_SEPARATOR.$entry);
    }
    @rmdir($path);
}

it('runs codeguard:install non-interactively with --preset=default and exits 0', function (): void {
    $exitCode = $this->artisan('codeguard:install', [
        '--no-interactive' => true,
        '--preset' => 'default',
    ])->run();

    expect($exitCode)->toBe(0);

    // Spot-check that at least one of the default stubs was published
    // into the temp base path.
    expect(file_exists($this->tempApp.'/pint.json'))->toBeTrue()
        ->and(file_exists($this->tempApp.'/phpstan.neon'))->toBeTrue();
});

it('creates .codeguard/.gitignore with canonical entries before stub publish', function (): void {
    $this->artisan('codeguard:install', [
        '--no-interactive' => true,
        '--preset' => 'default',
    ])->assertExitCode(0);

    $gitignore = $this->tempApp.'/.codeguard/.gitignore';
    expect(file_exists($gitignore))->toBeTrue();

    $content = file_get_contents($gitignore);
    foreach (CodeguardDirectoryInitializer::requiredEntries() as $entry) {
        expect($content)->toContain($entry);
    }
});

it('writes a recognisable stub payload (pint.json) to the target path', function (): void {
    $this->artisan('codeguard:install', [
        '--no-interactive' => true,
        '--preset' => 'default',
    ])->assertExitCode(0);

    $pint = file_get_contents($this->tempApp.'/pint.json');

    expect($pint)->toBeString()->not->toBeEmpty();
});

it('exits with code 2 when a pendency is recorded (captainhook binary missing)', function (): void {
    // Override the default happy-path installer with one that reports
    // BinaryMissing — this triggers an InstallSummary warning and the
    // "setup incomplete" branch of resolveExitCode().
    $this->app->singleton(CaptainhookInstaller::class, function (): CaptainhookInstaller {
        return new class(sys_get_temp_dir()) extends CaptainhookInstaller
        {
            public function install(EnvironmentInfo $env): CaptainhookInstallResult
            {
                return new CaptainhookInstallResult(
                    status: CaptainhookInstallStatus::BinaryMissing,
                    message: 'stubbed — binary missing',
                );
            }
        };
    });

    $exitCode = $this->artisan('codeguard:install', [
        '--no-interactive' => true,
        '--preset' => 'default',
    ])->run();

    expect($exitCode)->toBe(2);
});

it('writes install telemetry events when telemetry is enabled', function (): void {
    // Flip the gate on for just this test and point the recorder at a
    // path inside the temp app. The rest of the install flow (stubs,
    // captainhook, summary) is already stubbed out by beforeEach.
    $jsonlPath = $this->tempApp.DIRECTORY_SEPARATOR.'.codeguard'.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    $this->app->singleton(ConfigGate::class, fn () => new ConfigGate(enabled: true));
    $this->app->forgetInstance(Recorder::class);
    $this->app->singleton(Recorder::class, fn ($app) => new Recorder(
        gate: $app->make(ConfigGate::class),
        allowlist: new FieldAllowlist(strictMode: true),
        rotator: new Rotator,
        writer: new JsonlWriter,
        activePath: $jsonlPath,
    ));
    $this->app->forgetInstance(InstallTelemetry::class);
    $this->app->singleton(InstallTelemetry::class, fn ($app) => new InstallTelemetry(
        recorder: $app->make(Recorder::class),
    ));

    $this->artisan('codeguard:install', [
        '--no-interactive' => true,
        '--preset' => 'default',
    ])->assertExitCode(0);

    expect(file_exists($jsonlPath))->toBeTrue();

    $events = [];
    foreach (file($jsonlPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded) && isset($decoded['event']) && is_string($decoded['event'])) {
            $events[] = $decoded['event'];
        }
    }

    // Every event type the install flow should emit when reaching exit 0.
    expect($events)->toContain(
        'command.start',
        'install.env.detected',
        'install.preset.selected',
        'install.phpstan_extensions.selected',
        'install.captainhook.activated',
        'install.next_steps.rendered',
        'command.end',
    );
});

it('exits with code 2 when the captainhook plugin is blocked in composer.json (non-interactive)', function (): void {
    // Override the allow-plugins check with one that reports NotAllowed
    // and asserts that allow() was NOT called (non-interactive mode must
    // not mutate composer.json silently — only record a warning).
    $this->app->singleton(ComposerAllowPluginsCheck::class, function (): ComposerAllowPluginsCheck {
        return new class(new Filesystem, sys_get_temp_dir().'/unused.json') extends ComposerAllowPluginsCheck
        {
            public int $allowCalls = 0;

            public function check(string $plugin): AllowPluginsStatus
            {
                return AllowPluginsStatus::NotAllowed;
            }

            public function allow(string $plugin): bool
            {
                $this->allowCalls++;

                return true;
            }
        };
    });

    $exitCode = $this->artisan('codeguard:install', [
        '--no-interactive' => true,
        '--preset' => 'default',
    ])->run();

    expect($exitCode)->toBe(2);

    /** @var object{allowCalls:int} $check */
    $check = app(ComposerAllowPluginsCheck::class);
    expect($check->allowCalls)->toBe(0);
});

it('honors .codeguard/stub-overrides.yaml by skipping listed stubs on install', function (): void {
    // Pre-seed the overrides file so phpstan.neon is treated as permanently
    // customized before the install ever runs. This simulates the state the
    // file is in after a previous interactive session chose "Keep + remember".
    mkdir($this->tempApp.'/.codeguard', 0o755, recursive: true);
    file_put_contents(
        $this->tempApp.'/.codeguard/stub-overrides.yaml',
        "overrides:\n  - phpstan.neon\n",
    );

    $this->artisan('codeguard:install', [
        '--no-interactive' => true,
        '--preset' => 'default',
    ])->assertExitCode(0);

    // phpstan.neon is skipped (override honored); pint.json still published.
    expect(file_exists($this->tempApp.'/phpstan.neon'))->toBeFalse();
    expect(file_exists($this->tempApp.'/pint.json'))->toBeTrue();
});

it('bypasses stub-overrides.yaml when --refresh-stubs is passed (force flag)', function (): void {
    mkdir($this->tempApp.'/.codeguard', 0o755, recursive: true);
    file_put_contents(
        $this->tempApp.'/.codeguard/stub-overrides.yaml',
        "overrides:\n  - phpstan.neon\n",
    );

    $this->artisan('codeguard:install', [
        '--no-interactive' => true,
        '--preset' => 'default',
        '--refresh-stubs' => true,
    ])->assertExitCode(0);

    // --refresh-stubs is an explicit force: the override is ignored and the
    // file is created from the stub as usual.
    expect(file_exists($this->tempApp.'/phpstan.neon'))->toBeTrue();
});

it('phpstan extension applier respects stub-overrides.yaml', function (): void {
    // Pre-seed a customized phpstan.neon that would be corrupted if the
    // applier ran its sentinel toggles over it. The override list protects
    // the file; save() still records the user's extension selection so
    // subsequent runs remember it, even though nothing was written to disk.
    mkdir($this->tempApp.'/.codeguard', 0o755, recursive: true);
    file_put_contents(
        $this->tempApp.'/.codeguard/stub-overrides.yaml',
        "overrides:\n  - phpstan.neon\n",
    );
    $customContent = "# ARCH-CUSTOMIZED-SENTINEL\nparameters:\n  level: 10\n";
    file_put_contents($this->tempApp.'/phpstan.neon', $customContent);

    $this->artisan('codeguard:install', [
        '--no-interactive' => true,
        '--preset' => 'default',
    ])->assertExitCode(0);

    // File contents must be byte-identical — applier didn't touch sentinels.
    expect(file_get_contents($this->tempApp.'/phpstan.neon'))->toBe($customContent);
});

it('phpstan extension applier bypasses stub-overrides with --refresh-stubs', function (): void {
    mkdir($this->tempApp.'/.codeguard', 0o755, recursive: true);
    file_put_contents(
        $this->tempApp.'/.codeguard/stub-overrides.yaml',
        "overrides:\n  - phpstan.neon\n",
    );
    // phpstan.neon will be recreated from stub (override bypassed) → applier
    // then runs on the fresh stub — must not error.

    $this->artisan('codeguard:install', [
        '--no-interactive' => true,
        '--preset' => 'default',
        '--refresh-stubs' => true,
    ])->assertExitCode(0);

    // The published stub contains the sentinel markers the applier relies on.
    expect(file_get_contents($this->tempApp.'/phpstan.neon'))
        ->toContain('@codeguard:ext=');
});

it('deptrac wizard respects stub-overrides.yaml (deptrac.yaml not overwritten)', function (): void {
    // Seed overrides protecting deptrac.yaml + a pre-existing file we want
    // to survive. Without this fix, the wizard would rewrite it.
    mkdir($this->tempApp.'/.codeguard', 0o755, recursive: true);
    file_put_contents(
        $this->tempApp.'/.codeguard/stub-overrides.yaml',
        "overrides:\n  - deptrac.yaml\n",
    );

    // User's hand-tuned deptrac.yaml (sentinel content we expect to survive).
    file_put_contents(
        $this->tempApp.'/deptrac.yaml',
        "deptrac:\n  # CUSTOM-30-LAYER-SENTINEL\n  layers: []\n",
    );

    // Give the suggester something to find so the wizard path is actually
    // considered — otherwise maybeSuggestDeptracLayers short-circuits via
    // `$suggestion->isEmpty()`.
    mkdir($this->tempApp.'/app/Services', 0o755, recursive: true);
    file_put_contents(
        $this->tempApp.'/app/Services/X.php',
        "<?php\nnamespace App\\Services;\nclass X {}\n",
    );

    $this->artisan('codeguard:install', [
        '--no-interactive' => true,
        '--preset' => 'default',
    ])->assertExitCode(0);

    $contents = file_get_contents($this->tempApp.'/deptrac.yaml');
    expect($contents)->toContain('CUSTOM-30-LAYER-SENTINEL');
});

it('deptrac wizard bypasses stub-overrides.yaml when --refresh-stubs is passed', function (): void {
    mkdir($this->tempApp.'/.codeguard', 0o755, recursive: true);
    file_put_contents(
        $this->tempApp.'/.codeguard/stub-overrides.yaml',
        "overrides:\n  - deptrac.yaml\n",
    );
    file_put_contents(
        $this->tempApp.'/deptrac.yaml',
        "deptrac:\n  # CUSTOM-30-LAYER-SENTINEL\n  layers: []\n",
    );
    mkdir($this->tempApp.'/app/Services', 0o755, recursive: true);
    file_put_contents(
        $this->tempApp.'/app/Services/X.php',
        "<?php\nnamespace App\\Services;\nclass X {}\n",
    );

    $this->artisan('codeguard:install', [
        '--no-interactive' => true,
        '--preset' => 'default',
        '--refresh-stubs' => true,
    ])->assertExitCode(0);

    $contents = file_get_contents($this->tempApp.'/deptrac.yaml');
    // --refresh-stubs is an explicit force — the custom content is gone.
    expect($contents)->not->toContain('CUSTOM-30-LAYER-SENTINEL');
});

it('warns but does not delete legacy stubs (lefthook.yml) in non-interactive mode', function (): void {
    // Simulate a pre-CaptainHook project that still has lefthook.yml lying
    // around. The installer must warn without touching it — safety default
    // for CI runs.
    $legacyPath = $this->tempApp.'/lefthook.yml';
    file_put_contents($legacyPath, "pre-commit:\n  commands: {}\n");

    $exitCode = $this->artisan('codeguard:install', [
        '--no-interactive' => true,
        '--preset' => 'default',
    ])->run();

    // Exit 2 because a pendency (the legacy file) was recorded in summary.
    expect($exitCode)->toBe(2);
    // File MUST still exist — non-interactive must never delete silently.
    expect(file_exists($legacyPath))->toBeTrue();
});
