<?php

declare(strict_types=1);

namespace Syriable\Translator\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Syriable\Translator\Database\Factories\GroupFactory;
use Syriable\Translator\Models\Concerns\HasTranslatorTable;

/**
 * Represents a logical grouping of TranslationKeys.
 *
 * A Group corresponds to a single translation file on disk.
 * PHP translation files produce named groups (e.g. `auth`, `validation`),
 * while JSON files are consolidated under the reserved `_json` group name.
 * Vendor-namespaced files carry a non-null namespace to avoid key collisions.
 *
 * Table: {prefix}groups (default: ltu_groups)
 *
 * @property int $id
 * @property string $name Group name (e.g. 'auth', 'validation', '_json').
 * @property string|null $namespace Vendor namespace (e.g. 'spatie', null for app files).
 * @property string $file_format Source file format: 'php' or 'json'.
 * @property string|null $file_path Absolute path to the source file on disk.
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read  Collection<int, TranslationKey>  $translationKeys
 *
 * @method static Builder<Group> forNamespace(?string $namespace)
 * @method static Builder<Group> withFormat(string $format)
 * @method static Builder<Group> application()
 * @method static Builder<Group> vendor()
 */
final class Group extends Model
{
    use HasFactory;
    use HasTranslatorTable;

    protected static function newFactory(): GroupFactory
    {
        return GroupFactory::new();
    }

    /** @var string Base table name without prefix. */
    protected string $translatorTable = 'groups';

    /**
     * Reserved group name for all JSON (non-namespaced) translation keys.
     *
     * @see \Syriable\Translator\Services\Importer\TranslationImporter::JSON_GROUP_NAME
     */
    public const string JSON_GROUP_NAME = '_json';

    public const string FORMAT_PHP = 'php';

    public const string FORMAT_JSON = 'json';

    protected $fillable = [
        'name',
        'namespace',
        'file_format',
        'file_path',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'name' => 'string',
            'namespace' => 'string',
            'file_format' => 'string',
            'file_path' => 'string',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * All translation keys that belong to this group.
     *
     * @return HasMany<TranslationKey>
     */
    public function translationKeys(): HasMany
    {
        return $this->hasMany(
            related: config('translator.models.translation_key', TranslationKey::class),
            foreignKey: 'group_id',
        );
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Restrict results to groups belonging to the given vendor namespace.
     *
     * @param  Builder<Group>  $query
     * @return Builder<Group>
     */
    public function scopeForNamespace(Builder $query, ?string $namespace): Builder
    {
        return $query->where('namespace', $namespace);
    }

    /**
     * Restrict results to groups using the given file format.
     *
     * @param  Builder<Group>  $query
     * @return Builder<Group>
     */
    public function scopeWithFormat(Builder $query, string $format): Builder
    {
        return $query->where('file_format', $format);
    }

    /**
     * Restrict results to application-level groups (no vendor namespace).
     *
     * @param  Builder<Group>  $query
     * @return Builder<Group>
     */
    public function scopeApplication(Builder $query): Builder
    {
        return $query->whereNull('namespace');
    }

    /**
     * Restrict results to vendor-namespaced groups.
     *
     * @param  Builder<Group>  $query
     * @return Builder<Group>
     */
    public function scopeVendor(Builder $query): Builder
    {
        return $query->whereNotNull('namespace');
    }

    // -------------------------------------------------------------------------
    // Domain Methods
    // -------------------------------------------------------------------------

    /**
     * Determine whether this group is vendor-namespaced.
     */
    public function isVendor(): bool
    {
        return $this->namespace !== null;
    }

    /**
     * Determine whether this group holds JSON translation keys.
     */
    public function isJson(): bool
    {
        return $this->file_format === self::FORMAT_JSON;
    }

    /**
     * Return a qualified identifier combining namespace and group name.
     *
     * For vendor groups: `spatie::auth`.
     * For application groups: `auth`.
     */
    public function qualifiedName(): string
    {
        return $this->namespace !== null
            ? "{$this->namespace}::{$this->name}"
            : $this->name;
    }
}
