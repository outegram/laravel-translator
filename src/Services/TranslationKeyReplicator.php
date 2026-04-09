<?php

declare(strict_types=1);

namespace Syriable\Translator\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

/**
 * Ensures every active Language has a corresponding Translation row
 * for every TranslationKey in the system.
 *
 * Replication creates missing Translation records in bulk, assigning
 * an `Untranslated` status by default. Source-language rows may receive
 * an initial value and a `Translated` status when a source value is provided.
 *
 * Three replication scopes are supported:
 *  - All keys across all active languages   {@see replicateAllKeys()}
 *  - A single key across all active languages  {@see replicateSingleKey()}
 *  - All keys for a single language  {@see replicateKeysForLanguage()}
 *
 * Chunk size is read from config('translator.import.chunk_size').
 */
final class TranslationKeyReplicator
{
    /**
     * The name of the translations pivot table.
     *
     * Read from config to support custom table prefixes.
     */
    private const string TRANSLATIONS_TABLE_BASE = 'translations';

    /**
     * Replicate all TranslationKeys to every active language.
     *
     * Only missing Translation records are created — existing rows are not
     * overwritten. Each missing record is inserted with a null value and
     * an `Untranslated` status.
     */
    public function replicateAllKeys(): void
    {
        $activeLanguageIds = $this->resolveActiveLanguageIds();

        foreach ($activeLanguageIds as $languageId) {
            $this->replicateMissingKeysForLanguageId($languageId);
        }
    }

    /**
     * Replicate a single TranslationKey to every active language.
     *
     * When a source value is provided and the language is the designated
     * source language, the record is created with the given value and a
     * `Translated` status. All other languages receive a null value and
     * an `Untranslated` status.
     *
     * Uses firstOrCreate to guarantee idempotency — re-running does not
     * overwrite existing translations.
     *
     * @param  TranslationKey  $key  The key to replicate across all active languages.
     * @param  string|null  $sourceValue  Optional initial value for the source language.
     */
    public function replicateSingleKey(TranslationKey $key, ?string $sourceValue = null): void
    {
        $sourceLanguageId = $this->resolveSourceLanguageId();
        $activeLanguageIds = $this->resolveActiveLanguageIds();

        foreach ($activeLanguageIds as $languageId) {
            $isSourceLanguage = $languageId === $sourceLanguageId;

            $this->translationModel()::query()->firstOrCreate(
                [
                    'translation_key_id' => $key->id,
                    'language_id' => $languageId,
                ],
                [
                    'value' => $isSourceLanguage ? $sourceValue : null,
                    'status' => $this->resolveInitialStatus($isSourceLanguage, $sourceValue),
                ],
            );
        }
    }

    /**
     * Replicate all TranslationKeys to a single language.
     *
     * Intended for use when a new language is activated — all existing keys
     * are bulk-inserted for that language with a null value and Untranslated status.
     *
     * Uses upsert to handle concurrent inserts gracefully, updating only
     * `updated_at` on conflict to avoid overwriting any existing values.
     *
     * @param  Language  $language  The language to receive all existing translation keys.
     */
    public function replicateKeysForLanguage(Language $language): void
    {
        $this->translationKeyModel()::query()->chunkById(
            $this->chunkSize(),
            function (Collection $keys) use ($language): void {
                $this->upsertTranslationRecords($keys, $language->id);
            },
        );
    }

    /**
     * Replicate all TranslationKeys that are missing for the given language ID.
     *
     * Queries only keys that do not yet have a Translation row for this language,
     * avoiding redundant upserts on already-covered keys.
     */
    private function replicateMissingKeysForLanguageId(int $languageId): void
    {
        $this->translationKeyModel()::query()
            ->whereNotIn('id', function ($query) use ($languageId): void {
                $query->select('translation_key_id')
                    ->from($this->resolveTranslationsTable())
                    ->where('language_id', $languageId);
            })
            ->chunkById(
                $this->chunkSize(),
                function (Collection $keys) use ($languageId): void {
                    $this->upsertTranslationRecords($keys, $languageId);
                },
            );
    }

    /**
     * Bulk-upsert a collection of TranslationKeys as Translation rows for a given language.
     *
     * On conflict (duplicate translation_key_id + language_id), only `updated_at`
     * is refreshed — values and statuses are never overwritten by this operation.
     *
     * @param  Collection<int, TranslationKey>  $keys
     */
    private function upsertTranslationRecords(Collection $keys, int $languageId): void
    {
        $now = Carbon::now();

        $records = $keys->map(fn (TranslationKey $key): array => [
            'translation_key_id' => $key->id,
            'language_id' => $languageId,
            'value' => null,
            'status' => TranslationStatus::Untranslated->value,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        $this->translationModel()::query()->upsert(
            $records,
            uniqueBy: ['translation_key_id', 'language_id'],
            update: ['updated_at'],
        );
    }

    /**
     * Resolve the initial TranslationStatus for a new Translation record.
     */
    private function resolveInitialStatus(bool $isSourceLanguage, ?string $sourceValue): string
    {
        $isTranslated = $isSourceLanguage && filled($sourceValue);

        return $isTranslated
            ? TranslationStatus::Translated->value
            : TranslationStatus::Untranslated->value;
    }

    /**
     * Retrieve all active language IDs as a flat collection.
     *
     * @return Collection<int, int>
     */
    private function resolveActiveLanguageIds(): Collection
    {
        return $this->languageModel()::query()
            ->where('active', true)
            ->pluck('id');
    }

    /**
     * Retrieve the ID of the designated source language, or null if none is defined.
     */
    private function resolveSourceLanguageId(): ?int
    {
        return $this->languageModel()::query()
            ->where('is_source', true)
            ->value('id');
    }

    /**
     * Resolve the fully prefixed translations table name.
     *
     * Reads the prefix from config to match the table name used by the Translation model.
     */
    private function resolveTranslationsTable(): string
    {
        $prefix = config('translator.table_prefix', 'ltu_');

        return $prefix.self::TRANSLATIONS_TABLE_BASE;
    }

    /**
     * Resolve the chunk size from configuration.
     */
    private function chunkSize(): int
    {
        return (int) config('translator.import.chunk_size', 500);
    }

    // -------------------------------------------------------------------------
    // Model Resolvers
    // -------------------------------------------------------------------------

    /** @return class-string<Language> */
    private function languageModel(): string
    {
        return config('translator.models.language', Language::class);
    }

    /** @return class-string<TranslationKey> */
    private function translationKeyModel(): string
    {
        return config('translator.models.translation_key', TranslationKey::class);
    }

    /** @return class-string<Translation> */
    private function translationModel(): string
    {
        return config('translator.models.translation', Translation::class);
    }
}
