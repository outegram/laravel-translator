<?php

declare(strict_types=1);

namespace Syriable\Translator\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;
use Syriable\Translator\Models\Concerns\HasTranslatorTable;

/**
 * Records the outcome of a single translation export run.
 *
 * Table: {prefix}export_logs (default: ltu_export_logs)
 *
 * @property int $id
 * @property int $locale_count
 * @property int $file_count
 * @property int $key_count
 * @property int $duration_ms
 * @property string $source
 * @property string|null $triggered_by
 */
final class ExportLog extends Model
{
    use HasTranslatorTable;

    protected string $translatorTable = 'export_logs';

    protected $fillable = [
        'locale_count',
        'file_count',
        'key_count',
        'duration_ms',
        'source',
        'triggered_by',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'locale_count' => 'integer',
            'file_count' => 'integer',
            'key_count' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function scopeFromSource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    public function hasExportedFiles(): bool
    {
        return $this->file_count > 0;
    }

    public function formattedDuration(): string
    {
        return $this->duration_ms >= 1000
            ? round($this->duration_ms / 1000, 1).'s'
            : $this->duration_ms.'ms';
    }
}
