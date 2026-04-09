<?php

declare(strict_types=1);

namespace Syriable\Translator\DTOs;

/**
 * Immutable value object representing a single language definition
 * from the LanguageDataProvider catalogue.
 *
 * Replaces the raw associative array previously returned by the provider,
 * giving callers a typed, self-documenting structure with IDE autocompletion
 * and static analysis support.
 *
 * Used by LanguageResolver when creating new Language model records
 * for locale codes that do not yet exist in the database.
 *
 * @see \Syriable\Translator\Support\LanguageDataProvider
 * @see \Syriable\Translator\Services\Importer\LanguageResolver
 */
final readonly class LanguageDefinition
{
    /**
     * @param  string  $code  BCP 47 locale code (e.g. 'en', 'ar', 'pt-BR').
     * @param  string  $name  English display name (e.g. 'Arabic', 'Portuguese (Brazil)').
     * @param  string  $nativeName  Name as written in the language itself (e.g. 'العربية').
     * @param  bool  $rtl  Whether the language is written right-to-left.
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $nativeName,
        public bool $rtl = false,
    ) {}
}
