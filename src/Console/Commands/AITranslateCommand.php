<?php

declare(strict_types=1);

namespace Syriable\Translator\Console\Commands;

use Illuminate\Console\Command;
use Syriable\Translator\AI\TranslationProviderManager;
use Syriable\Translator\Console\Concerns\DisplayHelper;
use Syriable\Translator\DTOs\AI\TranslationEstimate;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\DTOs\AI\TranslationResponse;
use Syriable\Translator\Exceptions\AI\ProviderAuthenticationException;
use Syriable\Translator\Exceptions\AI\TranslationProviderException;
use Syriable\Translator\Jobs\TranslateKeysJob;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\TranslationKey;
use Syriable\Translator\Services\AI\AITranslationService;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

/**
 * Artisan command that AI-translates missing translation keys.
 *
 * Enforces the "no execution without cost preview" rule:
 *  1. Builds the translation request from user input.
 *  2. Estimates token usage and cost WITHOUT making any API call.
 *  3. Displays the full estimate breakdown to the user.
 *  4. Requires explicit confirmation before proceeding.
 *  5. Executes translation synchronously or dispatches to the queue.
 *
 * Queue usage:
 *   php artisan translator:ai-translate --target=ar --queue
 *   php artisan queue:work                         ← required in a separate process
 *
 * Usage:
 *   php artisan translator:ai-translate --target=ar
 *   php artisan translator:ai-translate --target=ar --group=auth
 *   php artisan translator:ai-translate --target=ar --provider=claude --queue
 *   php artisan translator:ai-translate --target=ar --force --no-interaction
 *   php artisan translator:ai-translate --target=ar --fresh-cache
 */
final class AITranslateCommand extends Command
{
    use DisplayHelper;

    protected $signature = 'translator:ai-translate
        {--source=        : Source language code (e.g. en). Defaults to configured source language.}
        {--target=        : Target language code to translate into (e.g. ar, fr, de).}
        {--group=         : Translate only keys belonging to this group (e.g. auth, validation).}
        {--provider=      : AI provider to use (e.g. claude). Defaults to configured default.}
        {--queue          : Dispatch translation jobs to the queue instead of running synchronously.}
        {--force          : Skip the cost confirmation prompt (use with caution in CI).}
        {--fresh-cache    : Ignore the translation cache and force a fresh API call for all keys.}';

    protected $description = 'AI-translate missing translation keys using Claude or another configured provider';

    public function handle(
        AITranslationService $service,
        TranslationProviderManager $manager,
    ): int {
        $this->displayHeader('AI Translate');

        try {
            return $this->executeTranslation($service, $manager);
        } catch (ProviderAuthenticationException $e) {
            error($e->getMessage());
            $this->line('  Set your API key in .env: ANTHROPIC_API_KEY=sk-ant-...');

            return self::FAILURE;
        } catch (TranslationProviderException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }
    }

    // -------------------------------------------------------------------------
    // Main Execution
    // -------------------------------------------------------------------------

    private function executeTranslation(
        AITranslationService $service,
        TranslationProviderManager $manager,
    ): int {
        $provider = $this->resolveProvider($manager);
        $sourceLanguage = $this->resolveSourceLanguage();
        $targetLanguage = $this->resolveTargetLanguage();
        $group = $this->option('group') ?: null;

        if ($this->option('fresh-cache')) {
            $this->clearTranslationCache($targetLanguage->code);
        }

        $keys = $this->resolveUntranslatedKeys($targetLanguage, $group);

        if (empty($keys)) {
            info("No untranslated keys found for [{$targetLanguage->code}]. Nothing to do.");
            info('Tip: Run translator:import first, or use --fresh-cache if cache is stale.');

            return self::SUCCESS;
        }

        $request = $this->buildRequest($sourceLanguage, $targetLanguage, $keys, $group);
        $estimate = $service->estimate($request, $provider);

        $this->displayEstimate($estimate);

        if (! $this->shouldProceed($estimate)) {
            info('Translation cancelled.');

            return self::SUCCESS;
        }

        return $this->option('queue')
            ? $this->dispatchToQueue($request, $targetLanguage, $provider, $estimate)
            : $this->executeDirectly($service, $request, $targetLanguage, $provider, $estimate);
    }

    // -------------------------------------------------------------------------
    // Option Resolution
    // -------------------------------------------------------------------------

    private function resolveProvider(TranslationProviderManager $manager): string
    {
        $provider = $this->option('provider')
            ?: (string) config('translator.ai.default_provider', 'claude');

        $driver = $manager->driver($provider);

        if (! $driver->isAvailable()) {
            throw new TranslationProviderException(
                provider: $provider,
                message: "Provider [{$provider}] is not available. Check your API key in .env.",
            );
        }

        return $provider;
    }

    private function resolveSourceLanguage(): Language
    {
        $code = $this->option('source') ?: config('translator.source_language', 'en');
        $language = Language::query()->where('code', $code)->first();

        if ($language === null) {
            $this->fail("Source language [{$code}] not found. Run translator:import first.");
        }

        return $language;
    }

    private function resolveTargetLanguage(): Language
    {
        $code = $this->option('target');

        if (blank($code)) {
            if (! $this->input->isInteractive()) {
                $this->fail('--target is required in non-interactive mode. Example: --target=ar');
            }

            $available = Language::query()
                ->active()
                ->where('is_source', false)
                ->pluck('name', 'code')
                ->toArray();

            if (empty($available)) {
                $this->fail('No active non-source languages found. Run translator:import first.');
            }

            $code = select(label: 'Select target language', options: $available);
        }

        $language = Language::query()->where('code', $code)->active()->first();

        if ($language === null) {
            $this->fail("Target language [{$code}] not found or is inactive.");
        }

        return $language;
    }

    // -------------------------------------------------------------------------
    // Cache Management
    // -------------------------------------------------------------------------

    /**
     * Clear the AI translation cache for the given target locale.
     *
     * This forces a fresh API call for all keys, overriding any previously
     * cached translations. Useful when source strings have changed.
     */
    private function clearTranslationCache(string $localeCode): void
    {
        warning("Clearing AI translation cache for [{$localeCode}]...");

        // Laravel's cache doesn't support pattern-based deletion on all stores.
        // We flush the entire AI cache prefix by tagging (when supported) or
        // advise the user to flush manually for non-tag-supporting stores.
        $store = config('translator.ai.cache.store');

        try {
            $cache = cache()->store($store);

            if (method_exists($cache, 'tags')) {
                $cache->tags(['translator_ai', "translator_ai_{$localeCode}"])->flush();
                info("Cache cleared for [{$localeCode}].");
            } else {
                // For stores without tag support (file, database), we cannot
                // selectively clear by prefix without iterating all keys.
                // Disable cache for this run instead.
                config(['translator.ai.cache.enabled' => false]);
                info('Cache bypassed for this run (store does not support tag-based clearing).');
            }
        } catch (Throwable) {
            config(['translator.ai.cache.enabled' => false]);
            info('Cache bypassed for this run.');
        }
    }

    // -------------------------------------------------------------------------
    // Key Discovery
    // -------------------------------------------------------------------------

    /**
     * Retrieve all untranslated key-value pairs for the target language.
     *
     * A key is included when:
     *  - The source language has a non-null value for it.
     *  - The target language has no translation row, or has a null value,
     *    or has Untranslated status.
     *
     * @return array<string, string> key => source value pairs.
     */
    private function resolveUntranslatedKeys(Language $targetLanguage, ?string $groupFilter): array
    {
        $sourceLanguage = Language::query()->where('is_source', true)->first();

        if ($sourceLanguage === null) {
            $this->fail('No source language configured. Run translator:import first.');
        }

        $query = TranslationKey::query()->with([
            'translations' => fn ($q) => $q->whereIn('language_id', [
                $sourceLanguage->id,
                $targetLanguage->id,
            ]),
            'group',
        ]);

        if ($groupFilter) {
            $query->whereHas('group', fn ($q) => $q->where('name', $groupFilter));
        }

        $keys = [];

        foreach ($query->cursor() as $translationKey) {
            $sourceTranslation = $translationKey->translations
                ->firstWhere('language_id', $sourceLanguage->id);

            $targetTranslation = $translationKey->translations
                ->firstWhere('language_id', $targetLanguage->id);

            if (
                $sourceTranslation?->value !== null
                && (
                    $targetTranslation === null
                    || $targetTranslation->value === null
                    || $targetTranslation->status->value === 'untranslated'
                )
            ) {
                $keys[$translationKey->key] = $sourceTranslation->value;
            }
        }

        return $keys;
    }

    // -------------------------------------------------------------------------
    // Request Building
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, string>  $keys
     */
    private function buildRequest(
        Language $sourceLanguage,
        Language $targetLanguage,
        array $keys,
        ?string $group,
    ): TranslationRequest {
        return new TranslationRequest(
            sourceLanguage: $sourceLanguage->code,
            targetLanguage: $targetLanguage->code,
            keys: $keys,
            groupName: $group ?? '_all',
            preservePlurals: true,
        );
    }

    // -------------------------------------------------------------------------
    // Estimate Display & Confirmation
    // -------------------------------------------------------------------------

    private function displayEstimate(TranslationEstimate $estimate): void
    {
        $this->newLine();
        warning('⚠️  Review this cost estimate before proceeding:');
        $this->newLine();

        $this->table(
            headers: ['Metric', 'Value'],
            rows: $estimate->toTableRows(),
        );
    }

    /**
     * Request explicit confirmation before executing.
     *
     * Skipped when --force is passed or in non-interactive mode (CI, cron).
     */
    private function shouldProceed(TranslationEstimate $estimate): bool
    {
        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        return confirm(
            label: 'Proceed with translation?',
            default: false,
        );
    }

    // -------------------------------------------------------------------------
    // Execution
    // -------------------------------------------------------------------------

    /**
     * Dispatch translation jobs to the queue in batches.
     *
     * IMPORTANT: After dispatching, you must run a queue worker to process
     * the jobs:
     *
     *   php artisan queue:work
     *   php artisan queue:work --queue=translations   (if TRANSLATOR_AI_QUEUE=translations)
     *
     * Jobs will not execute until a worker processes them.
     */
    private function dispatchToQueue(
        TranslationRequest $request,
        Language $targetLanguage,
        string $provider,
        TranslationEstimate $estimate,
    ): int {
        $batchSize = (int) config('translator.ai.batch_size', 50);
        $chunks = array_chunk($request->keys, $batchSize, preserve_keys: true);
        $jobCount = count($chunks);
        $queueName = config('translator.ai.queue', 'default');

        foreach ($chunks as $chunk) {
            $chunkRequest = new TranslationRequest(
                sourceLanguage: $request->sourceLanguage,
                targetLanguage: $request->targetLanguage,
                keys: $chunk,
                groupName: $request->groupName,
                namespace: $request->namespace,
                preservePlurals: $request->preservePlurals,
                context: $request->context,
            );

            TranslateKeysJob::dispatch($chunkRequest, $targetLanguage, $provider)
                ->onQueue($queueName);
        }

        $this->newLine();
        info("✅ Dispatched {$jobCount} translation job(s) to the [{$queueName}] queue.");
        $this->newLine();

        $this->table(
            headers: ['Detail', 'Value'],
            rows: [
                ['Jobs dispatched',  $jobCount],
                ['Keys per batch',   $batchSize],
                ['Total keys',       count($request->keys)],
                ['Target language',  $request->targetLanguage],
                ['Queue name',       $queueName],
                ['Estimated cost',   $estimate->formattedCost()],
            ],
        );

        $this->newLine();
        warning('Jobs are queued but NOT yet processed.');
        $this->line('  Run the queue worker to execute them:');
        $this->newLine();
        $this->line("    <comment>php artisan queue:work --queue={$queueName}</comment>");
        $this->newLine();
        $this->line('  Or process all queues:');
        $this->newLine();
        $this->line('    <comment>php artisan queue:work</comment>');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Execute translation synchronously, showing a spinner per batch.
     */
    private function executeDirectly(
        AITranslationService $service,
        TranslationRequest $request,
        Language $targetLanguage,
        string $provider,
        TranslationEstimate $estimate,
    ): int {
        $batchSize = (int) config('translator.ai.batch_size', 50);
        $chunks = array_chunk($request->keys, $batchSize, preserve_keys: true);
        $totalChunks = count($chunks);
        $totalTranslated = 0;
        $totalFailed = 0;
        $totalCost = 0.0;
        $fromCache = 0;

        foreach ($chunks as $index => $chunk) {
            $chunkNumber = $index + 1;
            $chunkRequest = new TranslationRequest(
                sourceLanguage: $request->sourceLanguage,
                targetLanguage: $request->targetLanguage,
                keys: $chunk,
                groupName: $request->groupName,
                namespace: $request->namespace,
                preservePlurals: $request->preservePlurals,
                context: $request->context,
            );

            $response = spin(
                callback: static fn (): TranslationResponse => $service->translate(
                    request: $chunkRequest,
                    language: $targetLanguage,
                    provider: $provider,
                    estimate: $index === 0 ? $estimate : null,
                ),
                message: "Translating batch {$chunkNumber}/{$totalChunks}...",
            );

            $totalTranslated += $response->translatedCount();
            $totalFailed += count($response->failedKeys);
            $totalCost += $response->actualCostUsd;

            if ($response->model === 'cache') {
                $fromCache += $response->translatedCount();
            }
        }

        $this->displayExecutionSummary($totalTranslated, $totalFailed, $totalCost, $fromCache);

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    private function displayExecutionSummary(
        int $translated,
        int $failed,
        float $cost,
        int $fromCache,
    ): void {
        $this->newLine();
        info('✅ Translation complete');

        $fromApi = $translated - $fromCache;

        $this->table(
            headers: ['Metric', 'Value'],
            rows: [
                ['Keys translated',    (string) $translated],
                ['  From API',         (string) $fromApi],
                ['  From cache',       (string) $fromCache],
                ['Keys failed',        (string) $failed],
                ['Actual cost',        '$'.number_format($cost, 4)],
            ],
        );

        if ($failed > 0) {
            warning("{$failed} key(s) could not be translated. Re-run to retry failed keys.");
        }

        if ($fromCache > 0) {
            info("{$fromCache} key(s) served from cache (no API cost). Use --fresh-cache to bypass.");
        }
    }
}
