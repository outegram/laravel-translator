<?php

declare(strict_types=1);

namespace Syriable\Translator;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Translation\Loader;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Syriable\Translator\AI\Estimators\TokenEstimator;
use Syriable\Translator\AI\Prompts\TranslationPromptBuilder;
use Syriable\Translator\AI\TranslationProviderManager;
use Syriable\Translator\Console\Commands;
use Syriable\Translator\Contracts\AITranslationServiceContract;
use Syriable\Translator\Contracts\TranslationExporterContract;
use Syriable\Translator\Contracts\TranslationImporterContract;
use Syriable\Translator\Services\AI\AITranslationService;
use Syriable\Translator\Services\Exporter\JsonFileWriter;
use Syriable\Translator\Services\Exporter\PhpFileWriter;
use Syriable\Translator\Services\Exporter\TranslationExporter;
use Syriable\Translator\Services\Importer\JsonTranslationFileLoader;
use Syriable\Translator\Services\Importer\LanguageResolver;
use Syriable\Translator\Services\Importer\PhpTranslationFileLoader;
use Syriable\Translator\Services\Importer\TranslationDirectoryExplorer;
use Syriable\Translator\Services\Importer\TranslationImporter;
use Syriable\Translator\Services\Importer\TranslationStringAnalyzer;
use Syriable\Translator\Services\Scanner\FileWalker;
use Syriable\Translator\Services\Scanner\TranslationKeyScanner;
use Syriable\Translator\Services\Scanner\TranslationUsageExtractor;
use Syriable\Translator\Services\TranslationKeyReplicator;
use Syriable\Translator\Translation\DatabaseTranslationLoader;

class TranslatorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-translator')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasMigration('create_translator_table')
            ->hasCommands([
                Commands\ImportCommand::class,
                Commands\ExportCommand::class,
                Commands\AITranslateCommand::class,
                Commands\AIStatsCommand::class,
                Commands\QueueDiagnosticCommand::class,
                Commands\ScanCommand::class,
                Commands\PruneLogsCommand::class,
                Commands\CoverageCommand::class,
                Commands\LanguagesCommand::class,
                Commands\ReviewCommand::class,
                Commands\DiffCommand::class,
                Commands\ProviderCheckCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->registerImporterServices();
        $this->registerExporterServices();
        $this->registerAiServices();
        $this->registerScannerServices();
        $this->registerContracts();
    }

    public function packageBooted(): void
    {
        $this->registerTranslationLoader();
        $this->registerSchedule();
    }

    // -------------------------------------------------------------------------
    // Service Registrations
    // -------------------------------------------------------------------------

    private function registerImporterServices(): void
    {
        $this->app->singleton(
            TranslationImporter::class,
            static fn ($app): TranslationImporter => new TranslationImporter(
                phpLoader: $app->make(PhpTranslationFileLoader::class),
                directoryExplorer: $app->make(TranslationDirectoryExplorer::class),
                jsonLoader: $app->make(JsonTranslationFileLoader::class),
                stringAnalyzer: $app->make(TranslationStringAnalyzer::class),
                keyReplicator: $app->make(TranslationKeyReplicator::class),
                languageResolver: $app->make(LanguageResolver::class),
            ),
        );
    }

    private function registerExporterServices(): void
    {
        $this->app->singleton(
            TranslationExporter::class,
            static fn ($app): TranslationExporter => new TranslationExporter(
                phpWriter: $app->make(PhpFileWriter::class),
                jsonWriter: $app->make(JsonFileWriter::class),
            ),
        );
    }

    /**
     * Register AI services as singletons so driver instances are cached
     * across the full request lifecycle.
     *
     * IMPORTANT: Contract aliases point to their concrete singleton via `alias()`.
     * Do not register `AITranslationService` under the `translator` string key:
     * that name is reserved for Laravel's {@see \Illuminate\Translation\Translator}
     * (used by `loadTranslationsFrom()`, `__()`, etc.).
     *
     * The package facade resolves {@see AITranslationServiceContract::class}.
     */
    private function registerAiServices(): void
    {
        $this->app->singleton(
            TranslationProviderManager::class,
            static fn ($app): TranslationProviderManager => new TranslationProviderManager(
                estimator: $app->make(TokenEstimator::class),
                promptBuilder: $app->make(TranslationPromptBuilder::class),
            ),
        );

        $this->app->singleton(
            AITranslationService::class,
            static fn ($app): AITranslationService => new AITranslationService(
                providerManager: $app->make(TranslationProviderManager::class),
            ),
        );
    }

    private function registerScannerServices(): void
    {
        $this->app->singleton(FileWalker::class);
        $this->app->singleton(TranslationUsageExtractor::class);

        $this->app->singleton(
            TranslationKeyScanner::class,
            static fn ($app): TranslationKeyScanner => new TranslationKeyScanner(
                walker: $app->make(FileWalker::class),
                extractor: $app->make(TranslationUsageExtractor::class),
            ),
        );
    }

    /**
     * Bind public contracts to their concrete singleton implementations using
     * `alias()`. This guarantees that any resolution path — direct class,
     * contract interface, or facade — returns the SAME singleton instance.
     *
     * Do NOT use `bind()` here; it would create a transient and break the
     * singleton guarantee that prevents repeated provider instantiation.
     */
    private function registerContracts(): void
    {
        // Fix: use alias() so the contract resolves to the existing singleton,
        // not a freshly-constructed transient object.
        $this->app->alias(AITranslationService::class, AITranslationServiceContract::class);
        $this->app->alias(TranslationImporter::class, TranslationImporterContract::class);
        $this->app->alias(TranslationExporter::class, TranslationExporterContract::class);
    }

    // -------------------------------------------------------------------------
    // Runtime Translation Loader
    // -------------------------------------------------------------------------

    /**
     * Replace Laravel's file-based translation loader with our database-backed
     * loader when `translator.loader.enabled` is true.
     *
     * The DatabaseTranslationLoader wraps the existing file loader, so it can
     * fall back to file-based loading when the DB returns no results (useful
     * during the initial import phase or in testing environments).
     */
    private function registerTranslationLoader(): void
    {
        if (! config('translator.loader.enabled', false)) {
            return;
        }

        $this->app->extend(
            'translation.loader',
            static fn (Loader $fileLoader, $app): DatabaseTranslationLoader => new DatabaseTranslationLoader($fileLoader),
        );
    }

    // -------------------------------------------------------------------------
    // Scheduler
    // -------------------------------------------------------------------------

    /**
     * Register translator:prune-logs with the Laravel scheduler (weekly) when
     * log retention is enabled. No manual scheduling required by the application.
     */
    private function registerSchedule(): void
    {
        if ((int) config('translator.log_retention_days', 90) <= 0) {
            return;
        }

        $this->callAfterResolving(
            Schedule::class,
            static function (Schedule $schedule): void {
                $schedule->command('translator:prune-logs')
                    ->weekly()
                    ->withoutOverlapping()
                    ->runInBackground();
            },
        );
    }
}
