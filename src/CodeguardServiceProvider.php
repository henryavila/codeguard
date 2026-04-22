<?php

declare(strict_types=1);

namespace Henryavila\Codeguard;

use Henryavila\Codeguard\Commands\CodeguardCheckCommand;
use Henryavila\Codeguard\Commands\CodeguardInstallCommand;
use Henryavila\Codeguard\Commands\Telemetry\ClearCommand as TelemetryClearCommand;
use Henryavila\Codeguard\Commands\Telemetry\DisableCommand as TelemetryDisableCommand;
use Henryavila\Codeguard\Commands\Telemetry\EnableCommand as TelemetryEnableCommand;
use Henryavila\Codeguard\Gates\GateRunner;
use Henryavila\Codeguard\Install\CaptainhookInstaller;
use Henryavila\Codeguard\Install\CodeguardDirectoryInitializer;
use Henryavila\Codeguard\Install\ComposerAllowPluginsCheck;
use Henryavila\Codeguard\Install\DeptracLayerSuggester;
use Henryavila\Codeguard\Install\DeptracLayerWizard;
use Henryavila\Codeguard\Install\EnvironmentDetector;
use Henryavila\Codeguard\Install\GatePlanRegistry;
use Henryavila\Codeguard\Install\InstallTelemetry;
use Henryavila\Codeguard\Install\LayerDecisionStore;
use Henryavila\Codeguard\Install\NextStepsReporter;
use Henryavila\Codeguard\Install\PhpstanExtensionApplier;
use Henryavila\Codeguard\Install\PhpstanExtensionSelector;
use Henryavila\Codeguard\Install\PhpstanExtensionStore;
use Henryavila\Codeguard\Install\PresetSelector;
use Henryavila\Codeguard\Install\StubDiffer;
use Henryavila\Codeguard\Install\StubPublisher;
use Henryavila\Codeguard\Install\StubRegistry;
use Henryavila\Codeguard\Telemetry\ConfigGate;
use Henryavila\Codeguard\Telemetry\FieldAllowlist;
use Henryavila\Codeguard\Telemetry\JsonlWriter;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Telemetry\Rotator;
use Henryavila\Codeguard\Telemetry\StopwatchScope;
use Henryavila\Codeguard\Telemetry\TelemetryStateStore;
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
        $this->registerTelemetryServices();
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

        $this->app->singleton(CaptainhookInstaller::class, function (Application $app): CaptainhookInstaller {
            return new CaptainhookInstaller(basePath: $app->basePath());
        });

        $this->app->singleton(ComposerAllowPluginsCheck::class, function (Application $app): ComposerAllowPluginsCheck {
            /** @var Filesystem $filesystem */
            $filesystem = $app->make(Filesystem::class);

            return new ComposerAllowPluginsCheck(
                filesystem: $filesystem,
                composerJsonPath: $app->basePath('composer.json'),
            );
        });

        $this->app->singleton(CodeguardDirectoryInitializer::class, function (Application $app): CodeguardDirectoryInitializer {
            /** @var Filesystem $filesystem */
            $filesystem = $app->make(Filesystem::class);

            return new CodeguardDirectoryInitializer(
                filesystem: $filesystem,
                basePath: $app->basePath(),
            );
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

    private function registerTelemetryServices(): void
    {
        $this->app->singleton(TelemetryStateStore::class, static function (Application $app): TelemetryStateStore {
            return new TelemetryStateStore(
                stateFilePath: $app->basePath('.codeguard'.DIRECTORY_SEPARATOR.'telemetry-state.json'),
            );
        });

        $this->app->singleton(ConfigGate::class, static function (Application $app): ConfigGate {
            /** @var TelemetryStateStore $store */
            $store = $app->make(TelemetryStateStore::class);
            $persisted = $store->read();

            $enabled = $persisted ?? (bool) $app['config']->get('codeguard.telemetry.enabled', false);

            return new ConfigGate(enabled: $enabled);
        });

        $this->app->singleton(FieldAllowlist::class, static function (Application $app): FieldAllowlist {
            /** @var bool $strict */
            $strict = (bool) $app['config']->get('codeguard.telemetry.strict_mode', true);

            return new FieldAllowlist(strictMode: $strict);
        });

        $this->app->singleton(JsonlWriter::class);

        $this->app->singleton(Rotator::class, static function (Application $app): Rotator {
            /** @var int $maxBytes */
            $maxBytes = (int) $app['config']->get('codeguard.telemetry.rotate_bytes', 10 * 1024 * 1024);
            /** @var int $retain */
            $retain = (int) $app['config']->get('codeguard.telemetry.retain_archives', 5);

            return new Rotator(maxBytes: $maxBytes, retain: $retain);
        });

        $this->app->singleton(Recorder::class, static function (Application $app): Recorder {
            /** @var string $relativePath */
            $relativePath = (string) $app['config']->get(
                'codeguard.telemetry.path',
                '.codeguard'.DIRECTORY_SEPARATOR.'telemetry.jsonl',
            );

            $activePath = str_starts_with($relativePath, DIRECTORY_SEPARATOR)
                ? $relativePath
                : $app->basePath($relativePath);

            return new Recorder(
                gate: $app->make(ConfigGate::class),
                allowlist: $app->make(FieldAllowlist::class),
                rotator: $app->make(Rotator::class),
                writer: $app->make(JsonlWriter::class),
                activePath: $activePath,
            );
        });

        $this->app->singleton(StopwatchScope::class, static function (Application $app): StopwatchScope {
            return new StopwatchScope(recorder: $app->make(Recorder::class));
        });

        $this->app->singleton(InstallTelemetry::class, static function (Application $app): InstallTelemetry {
            return new InstallTelemetry(recorder: $app->make(Recorder::class));
        });

        $this->app->singleton(GateRunner::class, static function (Application $app): GateRunner {
            return new GateRunner(
                recorder: $app->make(Recorder::class),
                workingDirectory: $app->basePath(),
            );
        });
    }

    private function bootConsole(): void
    {
        $this->commands([
            CodeguardInstallCommand::class,
            CodeguardCheckCommand::class,
            TelemetryEnableCommand::class,
            TelemetryDisableCommand::class,
            TelemetryClearCommand::class,
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
