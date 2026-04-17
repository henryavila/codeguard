<?php

declare(strict_types=1);

namespace Henryavila\Codeguard;

use Henryavila\Codeguard\Commands\CodeguardInstallCommand;
use Henryavila\Codeguard\Install\DeptracLayerSuggester;
use Henryavila\Codeguard\Install\DeptracLayerWizard;
use Henryavila\Codeguard\Install\EnvironmentDetector;
use Henryavila\Codeguard\Install\GatePlanRegistry;
use Henryavila\Codeguard\Install\LayerDecisionStore;
use Henryavila\Codeguard\Install\LefthookInstaller;
use Henryavila\Codeguard\Install\NextStepsReporter;
use Henryavila\Codeguard\Install\PhpstanExtensionApplier;
use Henryavila\Codeguard\Install\PhpstanExtensionSelector;
use Henryavila\Codeguard\Install\PhpstanExtensionStore;
use Henryavila\Codeguard\Install\PresetSelector;
use Henryavila\Codeguard\Install\StubDiffer;
use Henryavila\Codeguard\Install\StubPublisher;
use Henryavila\Codeguard\Install\StubRegistry;
use Henryavila\Codeguard\Testing\CodeguardConfig;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

final class CodeguardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            path: __DIR__.'/../config/codeguard.php',
            key: 'codeguard',
        );

        $this->app->singleton(CodeguardConfig::class, static function (Application $app): CodeguardConfig {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('codeguard', []);

            return CodeguardConfig::fromArray($config);
        });

        $this->registerInstallServices();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->bootConsole();
        }
    }

    private function registerInstallServices(): void
    {
        $stubsSourcePath = realpath(__DIR__.'/../resources/stubs') ?: __DIR__.'/../resources/stubs';

        $this->app->singleton(PresetSelector::class);
        $this->app->singleton(StubRegistry::class);
        $this->app->singleton(NextStepsReporter::class);
        $this->app->singleton(GatePlanRegistry::class);

        $this->app->singleton(DeptracLayerSuggester::class, function (Application $app): DeptracLayerSuggester {
            /** @var Filesystem $filesystem */
            $filesystem = $app->make(Filesystem::class);

            return new DeptracLayerSuggester(filesystem: $filesystem);
        });

        $this->app->singleton(DeptracLayerWizard::class);

        $this->app->singleton(LayerDecisionStore::class, function (Application $app): LayerDecisionStore {
            /** @var Filesystem $filesystem */
            $filesystem = $app->make(Filesystem::class);

            return new LayerDecisionStore(
                filesystem: $filesystem,
                path: $app->basePath('.codeguard'.DIRECTORY_SEPARATOR.'layer-decisions.yaml'),
            );
        });

        $this->app->singleton(LefthookInstaller::class, function (Application $app): LefthookInstaller {
            return new LefthookInstaller(basePath: $app->basePath());
        });

        $this->app->singleton(PhpstanExtensionSelector::class);

        $this->app->singleton(PhpstanExtensionStore::class, function (Application $app): PhpstanExtensionStore {
            /** @var Filesystem $filesystem */
            $filesystem = $app->make(Filesystem::class);

            return new PhpstanExtensionStore(
                filesystem: $filesystem,
                path: $app->basePath('.codeguard'.DIRECTORY_SEPARATOR.'phpstan-extensions.yaml'),
            );
        });

        $this->app->singleton(PhpstanExtensionApplier::class, function (Application $app): PhpstanExtensionApplier {
            /** @var Filesystem $filesystem */
            $filesystem = $app->make(Filesystem::class);

            return new PhpstanExtensionApplier(filesystem: $filesystem);
        });

        $this->app->singleton(StubDiffer::class, function (Application $app): StubDiffer {
            /** @var Filesystem $filesystem */
            $filesystem = $app->make(Filesystem::class);

            return new StubDiffer(filesystem: $filesystem);
        });

        $this->app->singleton(EnvironmentDetector::class, function (Application $app): EnvironmentDetector {
            /** @var Filesystem $filesystem */
            $filesystem = $app->make(Filesystem::class);

            return new EnvironmentDetector(
                filesystem: $filesystem,
                basePath: $app->basePath(),
            );
        });

        $this->app->singleton(StubPublisher::class, function (Application $app) use ($stubsSourcePath): StubPublisher {
            /** @var Filesystem $filesystem */
            $filesystem = $app->make(Filesystem::class);

            /** @var StubDiffer $differ */
            $differ = $app->make(StubDiffer::class);

            return new StubPublisher(
                filesystem: $filesystem,
                basePath: $app->basePath(),
                stubsSourcePath: $stubsSourcePath,
                differ: $differ,
            );
        });
    }

    private function bootConsole(): void
    {
        $this->commands([
            CodeguardInstallCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/codeguard.php' => $this->app->configPath('codeguard.php'),
        ], 'codeguard-config');

        $this->publishes([
            __DIR__.'/../resources/rules' => $this->app->basePath('.codeguard/rules'),
        ], 'codeguard-rules');

        $this->publishes([
            __DIR__.'/../resources/patterns' => $this->app->basePath('.codeguard/patterns-vendor'),
        ], 'codeguard-patterns');
    }
}
