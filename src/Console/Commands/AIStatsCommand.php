<?php

declare(strict_types=1);

namespace Syriable\Translator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Syriable\Translator\Console\Concerns\DisplayHelper;
use Syriable\Translator\Models\AITranslationLog;

use function Laravel\Prompts\info;

/**
 * Artisan command that displays AI translation usage statistics and cost summary.
 *
 * Aggregates data from AITranslationLog records to give visibility into
 * token consumption, total spend, and translation success rates — broken
 * down by provider and target language.
 *
 * Usage:
 * ```bash
 * php artisan translator:ai-stats
 * php artisan translator:ai-stats --provider=claude
 * php artisan translator:ai-stats --days=7
 * ```
 */
final class AIStatsCommand extends Command
{
    use DisplayHelper;

    protected $signature = 'translator:ai-stats
        {--provider= : Filter stats by provider name (e.g. claude, chatgpt)}
        {--days=30   : Number of days to include in the report (default: 30)}';

    protected $description = 'Display AI translation usage statistics and cost breakdown';

    public function handle(): int
    {
        $this->displayHeader('AI Usage Stats');

        $days = (int) ($this->option('days') ?: 30);
        $provider = $this->option('provider') ?: null;

        $query = AITranslationLog::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->when($provider, static fn (Builder $q) => $q->where('provider', $provider));

        if ($query->count() === 0) {
            info("No AI translation logs found for the last {$days} days.");

            return self::SUCCESS;
        }

        $this->displayOverallSummary($query->clone(), $days);
        $this->displayByProvider($query->clone());
        $this->displayByLanguage($query->clone());

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Summary sections
    // -------------------------------------------------------------------------

    /**
     * Display the overall cost and usage summary for the period.
     *
     * @param  Builder<AITranslationLog>  $query
     */
    private function displayOverallSummary(Builder $query, int $days): void
    {
        $totals = $query->selectRaw('
            COUNT(*) as total_runs,
            SUM(key_count) as total_keys,
            SUM(translated_count) as total_translated,
            SUM(failed_count) as total_failed,
            SUM(input_tokens_used) as total_input_tokens,
            SUM(output_tokens_used) as total_output_tokens,
            SUM(actual_cost_usd) as total_cost,
            SUM(estimated_cost_usd) as total_estimated,
            AVG(duration_ms) as avg_duration_ms
        ')->first();

        $this->newLine();
        info("📊 Summary — last {$days} days");

        $variance = $totals->total_estimated > 0
            ? round((($totals->total_cost - $totals->total_estimated) / $totals->total_estimated) * 100, 1)
            : 0.0;

        $variantLabel = $variance >= 0 ? "+{$variance}%" : "{$variance}%";

        $this->table(
            headers: ['Metric', 'Value'],
            rows: [
                ['Total runs',          number_format((int) $totals->total_runs)],
                ['Keys submitted',       number_format((int) $totals->total_keys)],
                ['Keys translated',      number_format((int) $totals->total_translated)],
                ['Keys failed',          number_format((int) $totals->total_failed)],
                ['Input tokens used',    number_format((int) $totals->total_input_tokens)],
                ['Output tokens used',   number_format((int) $totals->total_output_tokens)],
                ['Total tokens',         number_format((int) ($totals->total_input_tokens + $totals->total_output_tokens))],
                ['Actual cost (USD)',     '$'.number_format((float) $totals->total_cost, 4)],
                ['Estimated cost (USD)',  '$'.number_format((float) $totals->total_estimated, 4)],
                ['Cost variance',        $variantLabel],
                ['Avg. duration',        round((float) $totals->avg_duration_ms).'ms'],
            ],
        );
    }

    /**
     * Display a breakdown of usage and cost per AI provider.
     *
     * @param  Builder<AITranslationLog>  $query
     */
    private function displayByProvider(Builder $query): void
    {
        $byProvider = $query->selectRaw('
            provider,
            model,
            COUNT(*) as runs,
            SUM(key_count) as keys,
            SUM(translated_count) as translated,
            SUM(input_tokens_used + output_tokens_used) as total_tokens,
            SUM(actual_cost_usd) as cost
        ')
            ->groupBy('provider', 'model')
            ->orderByDesc('cost')
            ->get();

        $this->newLine();
        info('🤖 By Provider');

        $this->table(
            headers: ['Provider', 'Model', 'Runs', 'Keys', 'Translated', 'Tokens', 'Cost (USD)'],
            rows: $byProvider->map(static fn ($row): array => [
                $row->provider,
                $row->model,
                number_format((int) $row->runs),
                number_format((int) $row->keys),
                number_format((int) $row->translated),
                number_format((int) $row->total_tokens),
                '$'.number_format((float) $row->cost, 4),
            ])->toArray(),
        );
    }

    /**
     * Display a breakdown of usage and cost per target language.
     *
     * @param  Builder<AITranslationLog>  $query
     */
    private function displayByLanguage(Builder $query): void
    {
        $byLanguage = $query->selectRaw('
            target_language,
            COUNT(*) as runs,
            SUM(translated_count) as translated,
            SUM(failed_count) as failed,
            SUM(actual_cost_usd) as cost
        ')
            ->groupBy('target_language')
            ->orderByDesc('translated')
            ->limit(15)
            ->get();

        $this->newLine();
        info('🌍 By Target Language (top 15)');

        $this->table(
            headers: ['Language', 'Runs', 'Translated', 'Failed', 'Cost (USD)', 'Success %'],
            rows: $byLanguage->map(static function ($row): array {
                $total = (int) $row->translated + (int) $row->failed;
                $rate = $total > 0 ? round(((int) $row->translated / $total) * 100, 1) : 100.0;

                return [
                    $row->target_language,
                    number_format((int) $row->runs),
                    number_format((int) $row->translated),
                    number_format((int) $row->failed),
                    '$'.number_format((float) $row->cost, 4),
                    $rate.'%',
                ];
            })->toArray(),
        );
    }
}