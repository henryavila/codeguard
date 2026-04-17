<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\EnvironmentDetector;
use Henryavila\Codeguard\Install\EnvironmentInfo;
use Henryavila\Codeguard\Install\LefthookInstallResult;
use Henryavila\Codeguard\Install\LefthookInstallStatus;
use Henryavila\Codeguard\Install\LefthookInstaller;
use Henryavila\Codeguard\Install\StubPublisher;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->tempApp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-install-'.uniqid();
    mkdir($this->tempApp, 0o755, true);

    // Stub out EnvironmentDetector so we don't depend on the host machine
    // having node/composer/lefthook available or in any particular version.
    $fakeEnv = new EnvironmentInfo(
        phpVersion: '8.3.0',
        composerVersion: '2.7.0',
        nodeVersion: null,
        hasPackageJson: false,
        hasNodeModules: false,
        hasLefthookBinary: false,
    );

    $this->app->singleton(EnvironmentDetector::class, function () use ($fakeEnv): EnvironmentDetector {
        return new class($fakeEnv) extends EnvironmentDetector {
            public function __construct(private readonly EnvironmentInfo $env)
            {
                parent::__construct(new Filesystem(), sys_get_temp_dir());
            }

            public function detect(): EnvironmentInfo
            {
                return $this->env;
            }
        };
    });

    // Prevent Lefthook from shelling out.
    $this->app->singleton(LefthookInstaller::class, function (): LefthookInstaller {
        return new class(sys_get_temp_dir()) extends LefthookInstaller {
            public function install(EnvironmentInfo $env): LefthookInstallResult
            {
                return new LefthookInstallResult(
                    status: LefthookInstallStatus::BinaryMissing,
                    message: 'stubbed for test',
                );
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
            differ: $app->make(\Henryavila\Codeguard\Install\StubDiffer::class),
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
