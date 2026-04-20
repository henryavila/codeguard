<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\AllowPluginsStatus;
use Henryavila\Codeguard\Install\CaptainhookInstaller;
use Henryavila\Codeguard\Install\CaptainhookInstallResult;
use Henryavila\Codeguard\Install\CaptainhookInstallStatus;
use Henryavila\Codeguard\Install\ComposerAllowPluginsCheck;
use Henryavila\Codeguard\Install\EnvironmentDetector;
use Henryavila\Codeguard\Install\EnvironmentInfo;
use Henryavila\Codeguard\Install\StubDiffer;
use Henryavila\Codeguard\Install\StubPublisher;
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

    // Repoint StubPublisher at a writable temp directory instead of
    // Testbench's read-only fixture base path.
    $stubsSourcePath = realpath(__DIR__.'/../../resources/stubs')
        ?: __DIR__.'/../../resources/stubs';

    $this->app->singleton(StubPublisher::class, function ($app) use ($stubsSourcePath): StubPublisher {
        return new StubPublisher(
            filesystem: $app->make(Filesystem::class),
            basePath: $this->tempApp,
            stubsSourcePath: $stubsSourcePath,
            differ: $app->make(StubDiffer::class),
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
