<?php

declare(strict_types=1);

namespace Syriable\Translator\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;
use Syriable\Translator\Database\Factories\TranslationFactory;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Models\Concerns\HasTranslatorTable;

/**
 * Represents the translated value for a single TranslationKey in a single Language.
 *
 * The combination of translation_key_id and language_id is unique — one
 * Translation row exists per key-language pair. Rows are seeded by
 * TranslationKeyReplicator with a null value and Untranslated status, then
 * populated by the import pipeline or by human translators via the UI.
 *
 * Table: {prefix}translations (default: ltu_translations)
 *
 * @property int $id
 * @property int $translation_key_id Foreign key to the associated TranslationKey.
 * @property int $language_id Foreign key to the associated Language.
 * @property string|null $value The translated string, or null when untranslated.
 * @property TranslationStatus $status Current lifecycle status of this translation.
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read  TranslationKey  $translationKey
 * @property-read  Language        $language
 *
 * @method static Builder<Translation> withStatus(TranslationStatus $status)
 * @method static Builder<Translation> untranslated()
 * @method static Builder<Translation> translated()
 * @method static Builder<Translation> reviewed()
 * @method static Builder<Translation> source()
 * @method static Builder<Translation> forLocale(string $code)
 */
final class Translation extends Model
{
    use HasFactory;
    use HasTranslatorTable;

    protected static function newFactory(): TranslationFactory
    {
        return TranslationFactory::new();
    }

    /** @var string Base table name without prefix. */
    protected string $translatorTable = 'translations';

    protected $fillable = [
        'translation_key_id',
        'language_id',
        'value',
        'status',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'value' => 'string',
            'status' => TranslationStatus::class,
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The translation key this value belongs to.
     *
     * @return BelongsTo<TranslationKey, Translation>
     */
    public function translationKey(): BelongsTo
    {
        return $this->belongsTo(
            related: config('translator.models.translation_key', TranslationKey::class),
            foreignKey: 'translation_key_id',
        );
    }

    /**
     * The language this translation value is written in.
     *
     * @return BelongsTo<Language, Translation>
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(
            related: config('translator.models.language', Language::class),
            foreignKey: 'language_id',
        );
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Restrict results to translations with the given status.
     *
     * @param  Builder<Translation>  $query
     * @return Builder<Translation>
     */
    public function scopeWithStatus(Builder $query, TranslationStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Restrict results to untranslated records.
     *
     * @param  Builder<Translation>  $query
     * @return Builder<Translation>
     */
    public function scopeUntranslated(Builder $query): Builder
    {
        return $query->where('status', TranslationStatus::Untranslated);
    }

    /**
     * Restrict results to records that have been translated.
     *
     * @param  Builder<Translation>  $query
     * @return Builder<Translation>
     */
    public function scopeTranslated(Builder $query): Builder
    {
        return $query->where('status', TranslationStatus::Translated);
    }

    /**
     * Restrict results to records that have been reviewed.
     *
     * @param  Builder<Translation>  $query
     * @return Builder<Translation>
     */
    public function scopeReviewed(Builder $query): Builder
    {
        return $query->where('status', TranslationStatus::Reviewed);
    }

    /**
     * Restrict results to translations belonging to the source language.
     *
     * @param  Builder<Translation>  $query
     * @return Builder<Translation>
     */
    public function scopeSource(Builder $query): Builder
    {
        return $query->whereHas(
            'language',
            static fn (Builder $q) => $q->where('is_source', true),
        );
    }

    /**
     * Restrict results to translations for the given locale code.
     *
     * @param  Builder<Translation>  $query
     * @return Builder<Translation>
     */
    public function scopeForLocale(Builder $query, string $code): Builder
    {
        return $query->whereHas(
            'language',
            static fn (Builder $q) => $q->where('code', $code),
        );
    }

    // -------------------------------------------------------------------------
    // Domain Methods
    // -------------------------------------------------------------------------

    /**
     * Determine whether this translation has a non-empty value.
     */
    public function hasValue(): bool
    {
        return filled($this->value);
    }

    /**
     * Determine whether this translation is complete (translated or reviewed).
     */
    public function isComplete(): bool
    {
        return $this->status->isComplete();
    }
}
