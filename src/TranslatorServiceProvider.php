<?php

declare(strict_types=1);

namespace Syriable\Translator;

use Illuminate\Console\Scheduling\Schedule;
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
     * The TranslationProviderManager and AITranslationService are singletons so
     * resolved driver instances are cached across service calls in one request.
     *
     * The facade accessor (`translator`) is bound here so that Translator::estimate()
     * and Translator::translate() resolve to the same singleton instance.
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

        $this->app->alias(AITranslationService::class, 'translator');
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
     * Bind public contracts to their concrete implementations.
     *
     * Companion packages should type-hint against these contracts rather than
     * concrete classes. This keeps the companion decoupled from implementation
     * details and allows the underlying service to be swapped or extended.
     */
    private function registerContracts(): void
    {
        $this->app->bind(TranslationImporterContract::class, TranslationImporter::class);
        $this->app->bind(TranslationExporterContract::class, TranslationExporter::class);
        $this->app->bind(AITranslationServiceContract::class, AITranslationService::class);
    }

    // -------------------------------------------------------------------------
    // Scheduler
    // -------------------------------------------------------------------------

    /**
     * Register translator:prune-logs with the Laravel scheduler.
     *
     * Runs weekly when log_retention_days > 0. Consumers can override the
     * schedule by adding their own definition in routes/console.php. Set
     * TRANSLATOR_LOG_RETENTION_DAYS=0 to disable automatic pruning.
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