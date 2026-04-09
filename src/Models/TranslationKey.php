<?php

declare(strict_types=1);

namespace Syriable\Translator\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Override;
use Syriable\Translator\Database\Factories\TranslationKeyFactory;
use Syriable\Translator\Models\Concerns\HasTranslatorTable;

/**
 * Represents a single translatable key within a translation Group.
 *
 * Stores the key identifier (dot-notation for PHP files) along with metadata
 * inferred from the source string at import time: interpolation parameters,
 * HTML presence, and plural structure.
 *
 * Each TranslationKey has one Translation record per active Language,
 * managed by TranslationKeyReplicator.
 *
 * Table: {prefix}translation_keys (default: ltu_translation_keys)
 *
 * @property int $id
 * @property int $group_id Foreign key to the owning Group.
 * @property string $key Dot-notation key (e.g. 'auth.failed', 'Welcome :name').
 * @property string[]|null $parameters Parameter tokens from the source string (e.g. [':name', '{count}']).
 * @property bool $is_html Whether the source string contains inline HTML.
 * @property bool $is_plural Whether the source string uses Laravel's pipe plural syntax.
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read  Group                         $group
 * @property-read  Collection<int, Translation>  $translations
 *
 * @method static Builder<TranslationKey> withParameters()
 * @method static Builder<TranslationKey> plural()
 * @method static Builder<TranslationKey> html()
 */
final class TranslationKey extends Model
{
    use HasFactory;
    use HasTranslatorTable;

    protected static function newFactory(): TranslationKeyFactory
    {
        return TranslationKeyFactory::new();
    }

    /** @var string Base table name without prefix. */
    protected string $translatorTable = 'translation_keys';

    protected $fillable = [
        'group_id',
        'key',
        'parameters',
        'is_html',
        'is_plural',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'is_html' => 'boolean',
            'is_plural' => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The group this key belongs to.
     *
     * @return BelongsTo<Group, TranslationKey>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(
            related: config('translator.models.group', Group::class),
            foreignKey: 'group_id',
        );
    }

    /**
     * All translation values recorded for this key across every language.
     *
     * @return HasMany<Translation>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(
            related: config('translator.models.translation', Translation::class),
            foreignKey: 'translation_key_id',
        );
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Restrict results to keys that define interpolation parameters.
     *
     * @param  Builder<TranslationKey>  $query
     * @return Builder<TranslationKey>
     */
    public function scopeWithParameters(Builder $query): Builder
    {
        return $query->whereNotNull('parameters');
    }

    /**
     * Restrict results to keys that use plural pipe syntax.
     *
     * @param  Builder<TranslationKey>  $query
     * @return Builder<TranslationKey>
     */
    public function scopePlural(Builder $query): Builder
    {
        return $query->where('is_plural', true);
    }

    /**
     * Restrict results to keys whose source strings contain HTML.
     *
     * @param  Builder<TranslationKey>  $query
     * @return Builder<TranslationKey>
     */
    public function scopeHtml(Builder $query): Builder
    {
        return $query->where('is_html', true);
    }

    // -------------------------------------------------------------------------
    // Domain Methods
    // -------------------------------------------------------------------------

    /**
     * Determine whether this key defines any interpolation parameters.
     *
     * Used by TranslationParametersRule to short-circuit validation on
     * keys with no parameters.
     */
    public function hasParameters(): bool
    {
        return filled($this->parameters);
    }

    /**
     * Return the list of parameter tokens for this key.
     *
     * Returns the full token as it appears in the source string
     * (e.g. `':name'`, `'{count}'`). Returns an empty array when none defined.
     *
     * Used by TranslationParametersRule::missingParametersFor().
     *
     * @return string[]
     */
    public function parameterNames(): array
    {
        return $this->parameters ?? [];
    }
}
