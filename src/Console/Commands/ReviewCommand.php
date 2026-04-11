<?php

declare(strict_types=1);

namespace Syriable\Translator\Console\Commands;

use Illuminate\Console\Command;
use Syriable\Translator\Console\Concerns\DisplayHelper;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Artisan command that bulk-marks translated keys as Reviewed.
 *
 * This command makes the AI translation memory system useful without a UI:
 * once translations are approved via this command, they appear in the AI system
 * prompt as consistency anchors for future translation runs.
 *
 * Usage:
 * ```bash
 * # Review all translated keys for a locale
 * php artisan translator:review --locale=ar
 *
 * # Review only a specific group
 * php artisan translator:review --locale=ar --group=auth
 *
 * # Review translations produced by a specific AI provider
 * php artisan translator:review --locale=ar --provider=claude
 *
 * # Non-interactive (CI): review without confirmation
 * php artisan translator:review --locale=ar --force --no-interaction
 *
 * # Preview what would be reviewed without changing anything
 * php artisan translator:review --locale=ar --dry-run
 * ```
 */
final class ReviewCommand extends Command
{
    use DisplayHelper;

    protected $signature = 'translator:review
        {--locale=    : Target locale code to review (required)}
        {--group=     : Only review keys in this group}
        {--provider=  : Only review translations logged from this AI provider}
        {--force      : Skip confirmation prompt}
        {--dry-run    : Preview count without making changes}';

    protected $description = 'Bulk-mark translated keys as Reviewed to enable translation memory';

    public function handle(): int
    {
        $this->displayHeader('Review');

        $localeCode = $this->option('locale') ?: null;
        $groupFilter = $this->option('group') ?: null;

        if (blank($localeCode)) {
            error('--locale is required. Example: --locale=ar');

            return self::FAILURE;
        }

        /** @var Language|null $language */
        $language = Language::query()->where('code', $localeCode)->first();

        if ($language === null) {
            error("Language [{$localeCode}] not found. Run translator:import first.");

            return self::FAILURE;
        }

        $query = Translation::query()
            ->where('language_id', $language->id)
            ->where('status', TranslationStatus::Translated)
            ->whereNotNull('value');

        if ($groupFilter !== null) {
            $query->whereHas(
                'translationKey.group',
                fn ($q) => $q->where('name', $groupFilter),
            );
        }

        $count = $query->count();

        if ($count === 0) {
            info("No translated (unreviewed) keys found for [{$localeCode}]"
                .($groupFilter ? " in group [{$groupFilter}]" : '').'. Nothing to review.');

            return self::SUCCESS;
        }

        $this->newLine();
        info("Found {$count} translated key(s) ready for review:");

        $this->table(
            headers: ['Detail', 'Value'],
            rows: [
                ['Locale',       $localeCode],
                ['Language',     $language->name],
                ['Group filter', $groupFilter ?? '(all groups)'],
                ['Keys to mark', number_format($count)],
            ],
        );

        if ($this->option('dry-run')) {
            warning('Dry run — no keys were marked as Reviewed.');
            info("Re-run without --dry-run to approve {$count} key(s).");

            return self::SUCCESS;
        }

        if (! $this->option('force') && $this->input->isInteractive()) {
            $confirmed = confirm(
                label: "Mark {$count} key(s) as Reviewed?",
                default: false,
                hint: 'Reviewed translations are included in AI translation memory for future runs.',
            );

            if (! $confirmed) {
                info('Review cancelled.');

                return self::SUCCESS;
            }
        }

        // Bulk update — does NOT trigger model events to avoid N+1 observer calls.
        // Cache invalidation for the translation memory is handled separately below.
        $updated = Translation::query()
            ->where('language_id', $language->id)
            ->where('status', TranslationStatus::Translated)
            ->whereNotNull('value')
            ->when($groupFilter, fn ($q) => $q->whereHas(
                'translationKey.group',
                fn ($q) => $q->where('name', $groupFilter),
            ))
            ->update(['status' => TranslationStatus::Reviewed->value]);

        // Manually invalidate the AI prompt memory cache for this locale,
        // since the bulk update skips model events and the observer.
        $memoryCacheKey = \Syriable\Translator\AI\Prompts\TranslationPromptBuilder::MEMORY_CACHE_PREFIX
            .":{$localeCode}";
        \Illuminate\Support\Facades\Cache::forget($memoryCacheKey);

        $this->newLine();
        info("✅ Marked {$updated} translation(s) as Reviewed for [{$localeCode}].");
        info('Translation memory cache invalidated — next AI run will use the new approved examples.');

        return self::SUCCESS;
    }
}
