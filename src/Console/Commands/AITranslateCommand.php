<?php

declare(strict_types=1);

namespace Syriable\Translator\Console\Commands;

use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Syriable\Translator\AI\TranslationProviderManager;
use Syriable\Translator\Console\Concerns\DisplayHelper;
use Syriable\Translator\DTOs\AI\TranslationEstimate;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\DTOs\AI\TranslationResponse;
use Syriable\Translator\Exceptions\AI\ProviderAuthenticationException;
use Syriable\Translator\Exceptions\AI\TranslationProviderException;
use Syriable\Translator\Jobs\TranslateKeysJob;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\TranslationKey;
use Syriable\Translator\Services\AI\AITranslationService;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

/**
 * Artisan command that AI-translates missing translation keys.
 *
 * Key improvements in this version:
 *
 * 1. N+1 fix: `resolveUntranslatedKeys()` now uses `chunkById()` instead of
 *    `cursor()`. Laravel's cursor() does not honour eager loading — each model
 *    triggers separate queries for `translations` and `group`. With chunkById(),
 *    the `with()` constraints are applied per chunk (500 rows → 3 queries per
 *    chunk instead of 2n + 1).
 *
 * 2. Concurrency protection: A Cache lock prevents two simultaneous runs for the
 *    same target language from duplicating API calls and wasting budget.
 *
 * 3. No global config mutation: The previous `--fresh-cache` implementation called
 *    `config(['translator.ai.cache.enabled' => false])`, which corrupted the cache
 *    state for any other code running in the same process (Octane, queue workers).
 *    Now `bypassCache = true` is passed explicitly as a method parameter.
 *
 * 4. Job batching: `--queue` dispatches jobs via `Bus::batch()` for Horizon
 *    visibility, progress tracking, and proper failure aggregation.
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
        {--fresh-cache    : Bypass the translation cache and force a fresh API call for all keys.}
        {--no-lock        : Skip the concurrency lock (useful in CI when parallel runs are intentional).}';

    protected $description = 'AI-translate missing translation keys using Claude or another configured provider';

    public function handle(
        AITranslationService $service,
        TranslationProviderManager $manager,
    ): int {
        $this->displayHeader('AI Translate');

        try {
            return $this->executeWithLock($service, $manager);
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
    // Concurrency Lock
    // -------------------------------------------------------------------------

    /**
     * Acquire a per-language cache lock before running the translation pipeline.
     *
     * This prevents two simultaneous `translator:ai-translate --target=ar` commands
     * from both discovering the same untranslated keys, making duplicate API calls,
     * and wasting the translation budget.
     *
     * The lock is released in a `finally` block, so it is always freed even if
     * the translation throws an exception or exits early.
     *
     * Skip with `--no-lock` for CI pipelines that intentionally run parallel jobs
     * for different language batches under the same target.
     */
    private function executeWithLock(
        AITranslationService $service,
        TranslationProviderManager $manager,
    ): int {
        $target = $this->option('target') ?: 'unknown';

        if ($this->option('no-lock')) {
            return $this->executeTranslation($service, $manager);
        }

        $lockKey = "translator:ai-translate:{$target}";
        $lock = Cache::lock($lockKey, seconds: 600);

        if (! $lock->get()) {
            error("Another AI translation run is already active for [{$target}].");
            $this->line('  Wait for it to finish, or use --no-lock to bypass this check.');

            return self::FAILURE;
        }

        try {
            return $this->executeTranslation($service, $manager);
        } finally {
            $lock->release();
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

        // `--fresh-cache` is now a simple boolean flag passed to the service
        // rather than a global config mutation. Safe for Octane + queue workers.
        $bypassCache = (bool) $this->option('fresh-cache');

        if ($bypassCache) {
            info('Cache bypassed for this run — all keys will use fresh API calls.');
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

        if (! $this->shouldProceed()) {
            info('Translation cancelled.');

            return self::SUCCESS;
        }

        return $this->option('queue')
            ? $this->dispatchToQueue($request, $targetLanguage, $provider, $estimate, $bypassCache)
            : $this->executeDirectly($service, $request, $targetLanguage, $provider, $estimate, $bypassCache);
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

        /** @var Language|null $language */
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

            /** @var string $code */
            $code = select(label: 'Select target language', options: $available);
        }

        /** @var Language|null $language */
        $language = Language::query()->where('code', $code)->active()->first();

        if ($language === null) {
            $this->fail("Target language [{$code}] not found or is inactive.");
        }

        return $language;
    }

    // -------------------------------------------------------------------------
    // Key Discovery — N+1 Fix
    // -------------------------------------------------------------------------

    /**
     * Retrieve all untranslated key-value pairs for the target language.
     *
     * IMPORTANT: The previous implementation used `cursor()` with `with()`, which
     * does NOT eager-load relationships. Eloquent's cursor() streams one model at
     * a time via a generator; the `with()` constraint is effectively ignored and
     * every model triggers separate queries for `translations` and `group`.
     *
     * The fix uses `chunkById(500)`, which processes rows in batches of 500. The
     * `with()` eager load is applied correctly per chunk, reducing query count from
     * O(2n) to O(ceil(n/500) * 3) regardless of dataset size.
     *
     * @return array<string, string> key => source value pairs.
     */
    private function resolveUntranslatedKeys(Language $targetLanguage, ?string $groupFilter): array
    {
        /** @var Language|null $sourceLanguage */
        $sourceLanguage = Language::query()->where('is_source', true)->first();

        if ($sourceLanguage === null) {
            $this->fail('No source language configured. Run translator:import first.');
        }

        $keys = [];

        TranslationKey::query()
            ->with([
                'translations' => fn ($q) => $q->whereIn('language_id', [
                    $sourceLanguage->id,
                    $targetLanguage->id,
                ]),
                'group',
            ])
            ->when(
                $groupFilter,
                fn ($q) => $q->whereHas('group', fn ($q) => $q->where('name', $groupFilter)),
            )
            ->chunkById(500, function (Collection $chunk) use ($sourceLanguage, $targetLanguage, &$keys): void {
                foreach ($chunk as $translationKey) {
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
            });

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

    private function shouldProceed(): bool
    {
        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        return confirm(label: 'Proceed with translation?', default: false);
    }

    // -------------------------------------------------------------------------
    // Queue Execution — Bus::batch()
    // -------------------------------------------------------------------------

    /**
     * Dispatch translation jobs as a Laravel Bus batch.
     *
     * Using Bus::batch() instead of individual dispatches provides:
     * - Horizon visibility: batch progress is trackable in the dashboard.
     * - Failure aggregation: `allowFailures()` lets partial batches complete.
     * - Completion callbacks: hook into `->then()` for post-batch notifications.
     *
     * The `bypassCache` flag is carried into each job so that `--fresh-cache`
     * works correctly for queued runs without mutating global config.
     */
    private function dispatchToQueue(
        TranslationRequest $request,
        Language $targetLanguage,
        string $provider,
        TranslationEstimate $estimate,
        bool $bypassCache,
    ): int {
        $batchSize = (int) config('translator.ai.batch_size', 50);
        $chunks = array_chunk($request->keys, $batchSize, preserve_keys: true);
        $queueName = (string) config('translator.ai.queue', 'default');

        $jobs = [];

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

            $jobs[] = TranslateKeysJob::make($chunkRequest, $targetLanguage, $provider, $bypassCache)
                ->onQueue($queueName);
        }

        $jobCount = count($jobs);
        $batchName = "AI Translate [{$targetLanguage->code}] — {$request->keyCount()} keys";

        $batch = Bus::batch($jobs)
            ->name($batchName)
            ->allowFailures()
            ->dispatch();

        $this->newLine();
        info("✅ Dispatched batch [{$batch->id}] with {$jobCount} job(s) to the [{$queueName}] queue.");
        $this->newLine();

        $this->table(
            headers: ['Detail', 'Value'],
            rows: [
                ['Batch ID',         $batch->id],
                ['Jobs dispatched',  (string) $jobCount],
                ['Keys per batch',   (string) $batchSize],
                ['Total keys',       (string) count($request->keys)],
                ['Target language',  $request->targetLanguage],
                ['Queue name',       $queueName],
                ['Estimated cost',   $estimate->formattedCost()],
                ['Cache bypassed',   $bypassCache ? 'Yes' : 'No'],
            ],
        );

        $this->newLine();
        warning('Jobs are queued but NOT yet processed.');
        $this->line("  Run the worker: <comment>php artisan queue:work --queue={$queueName}</comment>");
        $this->newLine();

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Synchronous Execution
    // -------------------------------------------------------------------------

    private function executeDirectly(
        AITranslationService $service,
        TranslationRequest $request,
        Language $targetLanguage,
        string $provider,
        TranslationEstimate $estimate,
        bool $bypassCache,
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

            /** @var TranslationResponse $response */
            $response = spin(
                callback: static fn (): TranslationResponse => $service->translate(
                    request: $chunkRequest,
                    language: $targetLanguage,
                    provider: $provider,
                    estimate: $index === 0 ? $estimate : null,
                    bypassCache: $bypassCache,
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

        $this->displayExecutionSummary($totalTranslated, $totalFailed, $totalCost, $fromCache, $bypassCache);

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
        bool $bypassCache,
    ): void {
        $this->newLine();
        info('✅ Translation complete');

        $fromApi = $translated - $fromCache;

        $this->table(
            headers: ['Metric', 'Value'],
            rows: [
                ['Keys translated', (string) $translated],
                ['  From API',      (string) $fromApi],
                ['  From cache',    $bypassCache ? 'Bypassed' : (string) $fromCache],
                ['Keys failed',     (string) $failed],
                ['Actual cost',     '$'.number_format($cost, 4)],
            ],
        );

        if ($failed > 0) {
            warning("{$failed} key(s) could not be translated. Re-run to retry failed keys.");
        }

        if ($fromCache > 0 && ! $bypassCache) {
            info("{$fromCache} key(s) served from cache (no API cost). Use --fresh-cache to bypass.");
        }
    }
}
