<?php

declare(strict_types=1);

namespace Syriable\Translator\Models\Concerns;

/**
 * Applies the configured table prefix to Eloquent model table resolution.
 *
 * Each model using this trait must define a `$translatorTable` property
 * holding its base table name (without prefix). The prefix is read from
 * `config('translator.table_prefix')` at runtime, allowing the entire
 * package table prefix to be changed via a single config value.
 *
 * Usage in a model:
 * ```php
 * use HasTranslatorTable;
 * protected string $translatorTable = 'languages';
 * ```
 */
trait HasTranslatorTable
{
    /**
     * Resolve the table name by prepending the configured package prefix.
     *
     * Falls back to 'ltu_' when the config value is not yet available,
     * ensuring models remain functional before the application is fully booted.
     */
    public function getTable(): string
    {
        $prefix = config('translator.table_prefix', 'ltu_');

        return $prefix.$this->translatorTable;
    }
}
