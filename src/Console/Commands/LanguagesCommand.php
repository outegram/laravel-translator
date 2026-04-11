<?php

declare(strict_types=1);

namespace Syriable\Translator\Console\Commands;

use Illuminate\Console\Command;
use Syriable\Translator\Console\Concerns\DisplayHelper;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

use function Laravel\Prompts\info;

/**
 * Artisan command that lists all registered languages with metadata.
 *
 * Usage:
 * ```bash
 * php artisan translator:languages
 * php artisan translator:languages --active          # active only
 * php artisan translator:languages --with-coverage   # include translation %
 * php artisan translator:languages --format=json
 * ```
 */
final class LanguagesCommand extends Command
{
    use DisplayHelper;

    protected $signature = 'translator:languages
        {--active         : Show only active languages}
        {--with-coverage  : Include translated/reviewed key counts}
        {--format=table   : Output format: table (default) or json}';

    protected $description = 'List all registered languages with metadata';

    public function handle(): int
    {
        $this->displayHeader('Languages');

        $query = Language::query()->orderBy('code');

        if ($this->option('active')) {
            $query->active();
        }

        $languages = $query->get();

        if ($languages->isEmpty()) {
            info('No languages found. Run translator:import first.');

            return self::SUCCESS;
        }

        $totalKeys = TranslationKey::query()->count();
        $withCoverage = (bool) $this->option('with-coverage');
        $format = (string) ($this->option('format') ?: 'table');

        $rows = $languages->map(function (Language $language) use ($totalKeys, $withCoverage): array {
            $row = [
                'code' => $language->code,
                'name' => $language->name,
                'native_name' => $language->native_name,
                'rtl' => $language->rtl ? 'Yes' : 'No',
                'active' => $language->active ? 'Yes' : 'No',
                'source' => $language->is_source ? 'Yes' : 'No',
            ];

            if ($withCoverage && $totalKeys > 0) {
                $translated = Translation::query()
                    ->where('language_id', $language->id)
                    ->whereNotNull('value')
                    ->count();

                $reviewed = Translation::query()
                    ->where('language_id', $language->id)
                    ->reviewed()
                    ->whereNotNull('value')
                    ->count();

                $row['translated'] = number_format(round(($translated / $totalKeys) * 100, 1), 1).'%';
                $row['reviewed'] = number_format(round(($reviewed / $totalKeys) * 100, 1), 1).'%';
            }

            return $row;
        })->all();

        if ($format === 'json') {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $headers = ['Code', 'Name', 'Native', 'RTL', 'Active', 'Source'];

        if ($withCoverage) {
            $headers[] = 'Translated %';
            $headers[] = 'Reviewed %';
        }

        $this->newLine();
        info('Total languages: '.count($rows).($withCoverage ? ' | Total keys: '.number_format($totalKeys) : ''));
        $this->newLine();

        $this->table(
            headers: $headers,
            rows: array_map(fn (array $r): array => array_values($r), $rows),
        );

        return self::SUCCESS;
    }
}
