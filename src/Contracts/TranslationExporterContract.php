<?php

declare(strict_types=1);

namespace Syriable\Translator\Contracts;

use Syriable\Translator\DTOs\ExportOptions;
use Syriable\Translator\DTOs\ExportResult;

/**
 * Public contract for the translation export service.
 *
 * Companion packages and application code that depend on export functionality
 * should type-hint against this interface rather than the concrete class.
 *
 * @see \Syriable\Translator\Services\Exporter\TranslationExporter
 */
interface TranslationExporterContract
{
    /**
     * Execute a full translation export and return the aggregated result.
     *
     * Reads Translation records from the database and writes them back to disk
     * as PHP group files and JSON locale files, preserving the original Laravel
     * file structure including vendor-namespaced paths.
     *
     * Dispatches ExportCompleted on success (when enabled in config).
     *
     * @param  ExportOptions  $options  Runtime configuration for this export run.
     * @return ExportResult Immutable summary of the completed export.
     */
    public function export(ExportOptions $options): ExportResult;
}
