<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\Importer;

use Syriable\Translator\Models\Language;
use Syriable\Translator\Support\LanguageDataProvider;

/**
 * Resolves a Language model by locale code, creating or synchronising it as needed.
 *
 * Ensures every language encountered during an import:
 *  - Exists as a Language record.
 *  - Is marked as active.
 *  - Has the correct `is_source` flag relative to the configured source language.
 *
 * The source language is read from `config('translator.source_language')`.
 *
 * Single responsibility: Language model lifecycle management.
 * Reusable by any service that needs to resolve Language records from locale codes.
 */
final class LanguageResolver
{
    /**
     * Resolve or create the Language model for the given locale code.
     *
     * If the language already exists, its `active` and `is_source` attributes
     * are synchronised with the current configuration before returning.
     * If it does not exist, it is created using known language metadata when
     * available, falling back to the locale code as both name and native name.
     *
     * @param  string  $localeCode  BCP 47 locale code (e.g. 'en', 'ar', 'fr').
     * @return Language The resolved, active Language model.
     */
    public function resolve(string $localeCode): Language
    {
        $isSource = $this->isSourceLanguage($localeCode);

        /** @var Language|null $language */
        $language = $this->languageModel()::query()
            ->where('code', $localeCode)
            ->first();

        if ($language !== null) {
            return $this->synchronise($language, $isSource);
        }

        return $this->create($localeCode, $isSource);
    }

    /**
     * Determine whether the given locale code is the designated source language.
     *
     * Reads from `translator.source_language`, defaulting to `'en'`.
     */
    private function isSourceLanguage(string $localeCode): bool
    {
        return $localeCode === config('translator.source_language', 'en');
    }

    /**
     * Synchronise an existing Language record with the current configuration.
     *
     * Updates `active` and `is_source` only when they differ from the expected
     * values, avoiding unnecessary database writes on repeated imports.
     */
    private function synchronise(Language $language, bool $isSource): Language
    {
        if (! $language->active || $language->is_source !== $isSource) {
            $language->update([
                'active' => true,
                'is_source' => $isSource,
            ]);
        }

        return $language;
    }

    /**
     * Create a new Language record for an unrecognised locale code.
     *
     * Metadata (name, native name, RTL direction) is sourced from
     * LanguageDataProvider when available, falling back to the locale code.
     */
    private function create(string $localeCode, bool $isSource): Language
    {
        $definition = LanguageDataProvider::findByCode($localeCode);

        return $this->languageModel()::query()->create([
            'code' => $localeCode,
            'name' => $definition?->name ?? $localeCode,
            'native_name' => $definition?->nativeName ?? $localeCode,
            'rtl' => $definition?->rtl ?? false,
            'active' => true,
            'is_source' => $isSource,
        ]);
    }

    /**
     * Resolve the Language model class from configuration.
     *
     * Allows consuming applications to substitute a custom Language model
     * by overriding `translator.models.language`.
     *
     * @return class-string<Language>
     */
    private function languageModel(): string
    {
        return config('translator.models.language', Language::class);
    }
}
