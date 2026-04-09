<?php

declare(strict_types=1);

namespace Syriable\Translator\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Override;
use Syriable\Translator\Database\Factories\LanguageFactory;
use Syriable\Translator\Models\Concerns\HasTranslatorTable;

/**
 * Represents a language supported by the translation system.
 *
 * Each Language maps to a BCP 47 locale code and holds display metadata
 * used when rendering language selectors or creating new Translation records.
 * Only one language may be designated as the source language at a time.
 *
 * Table: {prefix}languages (default: ltu_languages)
 *
 * @property int $id
 * @property string $code BCP 47 locale code (e.g. 'en', 'ar', 'pt-BR').
 * @property string $name English display name (e.g. 'Arabic').
 * @property string $native_name Name in the language itself (e.g. 'العربية').
 * @property bool $rtl Whether the language reads right-to-left.
 * @property bool $active Whether the language is visible and importable.
 * @property bool $is_source Whether this is the reference source language.
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read  Collection<int, Translation>  $translations
 *
 * @method static Builder<Language> active()
 * @method static Builder<Language> source()
 * @method static Builder<Language> rtl()
 */
final class Language extends Model
{
    use HasFactory;
    use HasTranslatorTable;

    /** @var string Base table name without prefix. */
    protected string $translatorTable = 'languages';

    protected static function newFactory(): LanguageFactory
    {
        return LanguageFactory::new();
    }

    protected $fillable = [
        'code',
        'name',
        'native_name',
        'rtl',
        'active',
        'is_source',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'rtl' => 'boolean',
            'active' => 'boolean',
            'is_source' => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * All translation values recorded for this language.
     *
     * @return HasMany<Translation>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(
            related: config('translator.models.translation', Translation::class),
            foreignKey: 'language_id',
        );
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Restrict results to active languages only.
     *
     * @param  Builder<Language>  $query
     * @return Builder<Language>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Restrict results to the designated source language.
     *
     * @param  Builder<Language>  $query
     * @return Builder<Language>
     */
    public function scopeSource(Builder $query): Builder
    {
        return $query->where('is_source', true);
    }

    /**
     * Restrict results to right-to-left languages.
     *
     * @param  Builder<Language>  $query
     * @return Builder<Language>
     */
    public function scopeRtl(Builder $query): Builder
    {
        return $query->where('rtl', true);
    }

    // -------------------------------------------------------------------------
    // Domain Methods
    // -------------------------------------------------------------------------

    /**
     * Determine whether this language reads right-to-left.
     */
    public function isRtl(): bool
    {
        return $this->rtl;
    }

    /**
     * Determine whether this is the source (reference) language.
     */
    public function isSource(): bool
    {
        return $this->is_source;
    }
}
