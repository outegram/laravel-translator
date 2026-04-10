<?php

declare(strict_types=1);

namespace Syriable\Translator;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Syriable\Translator\AI\Estimators\TokenEstimator;
use Syriable\Translator\AI\Prompts\TranslationPromptBuilder;
use Syriable\Translator\AI\TranslationProviderManager;
use Syriable\Translator\Console\Commands;
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
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
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
            ]);
    }

    public function packageRegistered(): void
    {
        $this->registerImporterServices();
        $this->registerExporterServices();
        $this->registerAiServices();
        $this->registerScannerServices();
    }

    // -------------------------------------------------------------------------
    // Service Registrations
    // -------------------------------------------------------------------------

    /**
     * Register the import pipeline as a singleton.
     */
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

    /**
     * Register the export pipeline as a singleton.
     */
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
     * Register AI translation services and the provider manager.
     *
     * The TranslationProviderManager is a singleton so that resolved driver
     * instances are cached across multiple service calls within one request.
     *
     * AITranslationService is also a singleton to share the provider manager
     * instance rather than constructing a new one per injection.
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

    /**
     * Register the scanner pipeline as a singleton.
     *
     * FileWalker and TranslationUsageExtractor are stateless — they hold no
     * mutable state and are safe to share as singletons across the request.
     *
     * TranslationKeyScanner is also a singleton so the FileWalker instance
     * is not reconstructed on every injection.
     */
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
}
