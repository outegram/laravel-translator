<?php

declare(strict_types=1);

namespace Syriable\Translator\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Syriable\Translator\Database\Factories\AITranslationLogFactory;
use Syriable\Translator\Models\Concerns\HasTranslatorTable;

/**
 * Records the outcome of a single AI translation API call.
 *
 * Created by AITranslationService after every successful or partially failed
 * translation execution. Provides a full audit trail for cost tracking,
 * quality monitoring, and usage analytics.
 *
 * Table: {prefix}ai_translation_logs (default: ltu_ai_translation_logs)
 *
 * @property int $id
 * @property string $provider AI provider name (e.g. 'claude').
 * @property string $model Model used (e.g. 'claude-sonnet-4-6').
 * @property string $source_language BCP 47 source locale code.
 * @property string $target_language BCP 47 target locale code.
 * @property string|null $group_name Translation group name.
 * @property int $key_count Keys submitted for translation.
 * @property int $translated_count Keys successfully translated.
 * @property int $failed_count Keys that failed translation.
 * @property int $input_tokens_used Actual input tokens consumed.
 * @property int $output_tokens_used Actual output tokens consumed.
 * @property float $actual_cost_usd Actual cost in USD.
 * @property float $estimated_cost_usd Pre-execution estimated cost.
 * @property int $duration_ms API call duration in milliseconds.
 * @property string $source Trigger origin.
 * @property string|null $triggered_by User or process that triggered the translation.
 * @property string[]|null $failed_keys Keys that could not be translated.
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<AITranslationLog> forProvider(string $provider)
 * @method static Builder<AITranslationLog> forLanguagePair(string $source, string $target)
 * @method static Builder<AITranslationLog> withFailures()
 */
final class AITranslationLog extends Model
{
    use HasFactory;
    use HasTranslatorTable;

    /** @var string Base table name without prefix. */
    protected string $translatorTable = 'ai_translation_logs';

    protected static function newFactory(): AITranslationLogFactory
    {
        return AITranslationLogFactory::new();
    }

    protected $fillable = [
        'provider',
        'model',
        'source_language',
        'target_language',
        'group_name',
        'key_count',
        'translated_count',
        'failed_count',
        'input_tokens_used',
        'output_tokens_used',
        'actual_cost_usd',
        'estimated_cost_usd',
        'duration_ms',
        'source',
        'triggered_by',
        'failed_keys',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'key_count' => 'integer',
            'translated_count' => 'integer',
            'failed_count' => 'integer',
            'input_tokens_used' => 'integer',
            'output_tokens_used' => 'integer',
            'actual_cost_usd' => 'float',
            'estimated_cost_usd' => 'float',
            'duration_ms' => 'integer',
            'failed_keys' => 'array',
        ];
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Restrict results to logs for the given provider.
     *
     * @param  Builder<AITranslationLog>  $query
     * @return Builder<AITranslationLog>
     */
    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /**
     * Restrict results to logs for the given source → target language pair.
     *
     * @param  Builder<AITranslationLog>  $query
     * @return Builder<AITranslationLog>
     */
    public function scopeForLanguagePair(Builder $query, string $source, string $target): Builder
    {
        return $query->where('source_language', $source)
            ->where('target_language', $target);
    }

    /**
     * Restrict results to runs that had at least one failed key.
     *
     * @param  Builder<AITranslationLog>  $query
     * @return Builder<AITranslationLog>
     */
    public function scopeWithFailures(Builder $query): Builder
    {
        return $query->where('failed_count', '>', 0);
    }

    // -------------------------------------------------------------------------
    // Domain Methods
    // -------------------------------------------------------------------------

    /**
     * Return the total tokens consumed (input + output).
     */
    public function totalTokensUsed(): int
    {
        return $this->input_tokens_used + $this->output_tokens_used;
    }

    /**
     * Return the actual cost formatted as a USD string.
     */
    public function formattedActualCost(): string
    {
        return '$'.number_format($this->actual_cost_usd, 4);
    }

    /**
     * Return the estimated cost formatted as a USD string.
     */
    public function formattedEstimatedCost(): string
    {
        return '$'.number_format($this->estimated_cost_usd, 4);
    }

    /**
     * Return the cost variance between estimate and actual as a percentage.
     *
     * A positive value means the actual was more expensive than estimated.
     * Returns 0.0 when the estimate was zero (first run, no baseline).
     */
    public function costVariancePercent(): float
    {
        if ($this->estimated_cost_usd === 0.0) {
            return 0.0;
        }

        return round(
            (($this->actual_cost_usd - $this->estimated_cost_usd) / $this->estimated_cost_usd) * 100,
            1,
        );
    }

    /**
     * Determine whether this translation run had any failures.
     */
    public function hadFailures(): bool
    {
        return $this->failed_count > 0;
    }

    /**
     * Return the translation success rate as a percentage.
     */
    public function successRate(): float
    {
        if ($this->key_count === 0) {
            return 100.0;
        }

        return round(($this->translated_count / $this->key_count) * 100, 1);
    }
}
