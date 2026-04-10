<?php

declare(strict_types=1);

namespace Syriable\Translator\Contracts;

use Syriable\Translator\DTOs\ImportOptions;
use Syriable\Translator\DTOs\ImportResult;

/**
 * Public contract for the translation import service.
 *
 * Companion packages and application code that depend on import functionality
 * should type-hint against this interface rather than the concrete class,
 * allowing the implementation to be swapped without breaking dependents.
 *
 * @see \Syriable\Translator\Services\Importer\TranslationImporter
 */
interface TranslationImporterContract
{
    /**
     * Execute a full translation import and return the aggregated result.
     *
     * Reads PHP and JSON translation files from the configured lang directory
     * and persists their contents to the database as Language, Group,
     * TranslationKey, and Translation records.
     *
     * Dispatches ImportCompleted on success (when enabled in config).
     *
     * @param  ImportOptions  $options  Runtime configuration for this import run.
     * @return ImportResult Immutable summary of the completed import.
     */
    public function import(ImportOptions $options): ImportResult;
}
