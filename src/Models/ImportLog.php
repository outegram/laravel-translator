<?php

declare(strict_types=1);

namespace Syriable\Translator\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;
use Syriable\Translator\Database\Factories\ImportLogFactory;
use Syriable\Translator\Models\Concerns\HasTranslatorTable;

/**
 * Records the outcome of a single translation import run.
 *
 * Created at the end of each import by TranslationImporter::recordImportLog()
 * and dispatched alongside the ImportCompleted event. Provides an audit trail
 * of every import executed, including who triggered it, how many keys were
 * affected, and how long the operation took.
 *
 * Table: {prefix}import_logs (default: ltu_import_logs)
 *
 * @property int $id
 * @property int $locale_count Number of locales processed.
 * @property int $key_count Total translation keys evaluated.
 * @property int $new_count Keys newly inserted during this run.
 * @property int $updated_count Existing keys whose values were updated.
 * @property int $duration_ms Wall-clock duration of the import in milliseconds.
 * @property string $source Trigger origin: 'cli', 'ui', or 'api'.
 * @property string|null $triggered_by Identifier of the user or process that triggered the import.
 * @property bool $fresh Whether all existing data was purged before importing.
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<ImportLog> fromSource(string $source)
 * @method static Builder<ImportLog> fresh()
 */
final class ImportLog extends Model
{
    /** @use HasFactory<ImportLogFactory> */
    use HasFactory;

    use HasTranslatorTable;

    /** @var string Base table name without prefix. */
    protected string $translatorTable = 'import_logs';

    protected static function newFactory(): ImportLogFactory
    {
        return ImportLogFactory::new();
    }

    protected $fillable = [
        'locale_count',
        'key_count',
        'new_count',
        'updated_count',
        'duration_ms',
        'source',
        'triggered_by',
        'fresh',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'locale_count' => 'integer',
            'key_count' => 'integer',
            'new_count' => 'integer',
            'updated_count' => 'integer',
            'duration_ms' => 'integer',
            'fresh' => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Restrict results to logs triggered from the given source.
     *
     * @param  Builder<ImportLog>  $query
     * @return Builder<ImportLog>
     */
    public function scopeFromSource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    /**
     * Restrict results to fresh (destructive) import runs.
     *
     * @param  Builder<ImportLog>  $query
     * @return Builder<ImportLog>
     */
    public function scopeFresh(Builder $query): Builder
    {
        return $query->where('fresh', true);
    }

    // -------------------------------------------------------------------------
    // Domain Methods
    // -------------------------------------------------------------------------

    /**
     * Determine whether this import run produced any changes (insertions or updates).
     */
    public function hasImportedChanges(): bool
    {
        return $this->new_count > 0 || $this->updated_count > 0;
    }

    /**
     * Return the total number of affected keys (inserted + updated).
     */
    public function affectedCount(): int
    {
        return $this->new_count + $this->updated_count;
    }

    /**
     * Return the import duration formatted as a human-readable string.
     *
     * Example output: `'1.2s'`, `'340ms'`
     */
    public function formattedDuration(): string
    {
        return $this->duration_ms >= 1000
            ? round($this->duration_ms / 1000, 1).'s'
            : $this->duration_ms.'ms';
    }
}
