<?php

declare(strict_types=1);

namespace Syriable\Translator\DTOs;

/**
 * Immutable value object encapsulating all runtime configuration for a
 * translation import operation.
 *
 * Combines caller-supplied overrides with application config values read once
 * at construction time, giving the importer a single typed source of truth for
 * every decision it must make — avoiding repeated config() calls per-key.
 *
 * Typical usage:
 * ```php
 * // From a CLI command or scheduled job:
 * $options = ImportOptions::fromConfig(['fresh' => true, 'triggered_by' => 'scheduler']);
 *
 * // Fully explicit (e.g. in tests):
 * $options = new ImportOptions(overwrite: false, detectHtml: false);
 * ```
 *
 * @see \Syriable\Translator\Services\Importer\TranslationImporter
 */
final readonly class ImportOptions
{
    /**
     * @param  bool  $overwrite  Overwrite existing translation values when re-importing.
     * @param  bool  $fresh  Purge all existing data before importing.
     * @param  string  $source  Origin of the import trigger (e.g. 'cli', 'ui', 'api').
     * @param  string|null  $triggeredBy  Identifier of the user or process that triggered the import.
     * @param  bool  $detectParameters  Analyse and store interpolation parameters (e.g. `:name`, `{count}`).
     * @param  bool  $detectHtml  Flag translation strings that contain inline HTML.
     * @param  bool  $detectPlural  Detect Laravel plural pipe syntax (`one|many`).
     * @param  bool  $scanVendor  Include vendor-namespaced translation files in the import.
     */
    public function __construct(
        public bool $overwrite = true,
        public bool $fresh = false,
        public string $source = 'cli',
        public ?string $triggeredBy = null,
        public bool $detectParameters = true,
        public bool $detectHtml = true,
        public bool $detectPlural = true,
        public bool $scanVendor = true,
    ) {}

    /**
     * Create an ImportOptions instance by merging caller-supplied overrides
     * with values read from the application configuration.
     *
     * Config keys read:
     *  - `translator.import.overwrite`
     *  - `translator.import.detect_parameters`
     *  - `translator.import.detect_html`
     *  - `translator.import.detect_plural`
     *  - `translator.import.scan_vendor`
     *
     * @param  array<string, mixed>  $overrides  Runtime overrides (e.g. from a CLI command or HTTP request).
     */
    public static function fromConfig(array $overrides = []): self
    {
        return new self(
            overwrite: $overrides['overwrite'] ?? config('translator.import.overwrite', true),
            fresh: $overrides['fresh'] ?? false,
            source: $overrides['source'] ?? 'cli',
            triggeredBy: $overrides['triggered_by'] ?? null,
            detectParameters: config('translator.import.detect_parameters', true),
            detectHtml: config('translator.import.detect_html', true),
            detectPlural: config('translator.import.detect_plural', true),
            scanVendor: config('translator.import.scan_vendor', true),
        );
    }
}
