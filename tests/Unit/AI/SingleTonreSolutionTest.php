<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator as LaravelTranslatorContract;
use Syriable\Translator\Contracts\AITranslationServiceContract;
use Syriable\Translator\Contracts\TranslationExporterContract;
use Syriable\Translator\Contracts\TranslationImporterContract;
use Syriable\Translator\Facades\Translator;
use Syriable\Translator\Services\AI\AITranslationService;
use Syriable\Translator\Services\Exporter\TranslationExporter;
use Syriable\Translator\Services\Importer\TranslationImporter;

/**
 * Verifies that the critical singleton-alias fix in TranslatorServiceProvider
 * is working correctly.
 *
 * CONTEXT: The previous implementation used `$this->app->bind()` for contracts,
 * which created a NEW transient instance on every resolution. This meant:
 *
 *   - `app(AITranslationService::class)`       → singleton instance A
 *   - `app(AITranslationServiceContract::class)` → NEW transient instance B
 *   - `Translator::getFacadeRoot()`            → NEW transient instance C
 *
 * The fix uses `$this->app->alias()` instead, which returns the existing
 * singleton for all resolution paths. Tests below verify the fix is in effect.
 */
describe('Container singleton resolution', function (): void {

    // -------------------------------------------------------------------------
    // AITranslationService
    // -------------------------------------------------------------------------

    describe('AITranslationService', function (): void {

        it('resolves the same instance via concrete class and contract', function (): void {
            $concrete = app(AITranslationService::class);
            $viaContract = app(AITranslationServiceContract::class);

            expect($concrete)->toBe($viaContract);
        });

        it('does not register AI translation under Laravel\'s translator binding', function (): void {
            $ai = app(AITranslationService::class);
            $laravelTranslator = app('translator');

            expect($laravelTranslator)->toBeInstanceOf(LaravelTranslatorContract::class)
                ->and($laravelTranslator)->not->toBe($ai);
        });

        it('resolves the same instance via concrete class and contract simultaneously', function (): void {
            $concrete = app(AITranslationService::class);
            $contract = app(AITranslationServiceContract::class);

            expect($concrete)->toBe($contract);
        });

        it('Translator facade root resolves to the same singleton', function (): void {
            $fromContainer = app(AITranslationService::class);

            // The facade must resolve the same singleton — not a fresh transient.
            // This is the critical test for the getFacadeAccessor() → alias chain.
            expect(Translator::getFacadeRoot())->toBe($fromContainer);
        });

        it('repeated resolution does not create new instances', function (): void {
            $first = app(AITranslationServiceContract::class);
            $second = app(AITranslationServiceContract::class);
            $third = app(AITranslationServiceContract::class);

            expect($first)->toBe($second)
                ->and($second)->toBe($third);
        });
    });

    // -------------------------------------------------------------------------
    // TranslationImporter
    // -------------------------------------------------------------------------

    describe('TranslationImporter', function (): void {

        it('resolves the same instance via concrete class and contract', function (): void {
            $concrete = app(TranslationImporter::class);
            $viaContract = app(TranslationImporterContract::class);

            expect($concrete)->toBe($viaContract);
        });
    });

    // -------------------------------------------------------------------------
    // TranslationExporter
    // -------------------------------------------------------------------------

    describe('TranslationExporter', function (): void {

        it('resolves the same instance via concrete class and contract', function (): void {
            $concrete = app(TranslationExporter::class);
            $viaContract = app(TranslationExporterContract::class);

            expect($concrete)->toBe($viaContract);
        });
    });
});
