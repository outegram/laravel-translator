<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Syriable\Translator\AI\TranslationProviderManager;
use Syriable\Translator\Contracts\AITranslationServiceContract;
use Syriable\Translator\DTOs\AI\TranslationEstimate;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\DTOs\AI\TranslationResponse;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Events\AITranslationCompleted;
use Syriable\Translator\Models\AITranslationLog;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

/**
 * Orchestrates the AI-powered translation workflow.
 *
 * Key improvements in this version:
 *
 * 1. `persistTranslations()` wraps all DB writes in a single `DB::transaction()`
 *    and uses `upsert()` instead of separate insert/saveQuietly() calls.
 *    This prevents partial writes under concurrent queue workers and eliminates
 *    the unique-constraint race condition.
 *
 * 2. `bypassCache` is now an explicit parameter instead of a global config mutation.
 *    The previous `config(['translator.ai.cache.enabled' => false])` call mutated
 *    shared runtime state and corrupted cache behaviour in Octane and workers.
 *
 * 3. Cache key building is exposed as a public static method so the observer can
 *    clear the same keys when translations are updated outside the service.
 */
final readonly class AITranslationService implements AITranslationServiceContract
{
    public function __construct(
        private TranslationProviderManager $providerManager,
    ) {}

    // -------------------------------------------------------------------------
    // Estimation (No API Call)
    // -------------------------------------------------------------------------

    public function estimate(TranslationRequest $request, ?string $provider = null): TranslationEstimate
    {
        return $this->providerManager
            ->driver($provider)
            ->estimate($request);
    }

    // -------------------------------------------------------------------------
    // Translation Execution
    // -------------------------------------------------------------------------

    /**
     * Execute the translation request, persist results, log the run, and
     * dispatch the AITranslationCompleted event.
     *
     * @param  bool  $bypassCache  Skip cache reads for this specific run only.
     *                             Does NOT mutate global config — safe for Octane.
     */
    public function translate(
        TranslationRequest $request,
        Language $language,
        ?string $provider = null,
        ?TranslationEstimate $estimate = null,
        bool $bypassCache = false,
    ): TranslationResponse {
        [$cachedTranslations, $uncachedRequest] = $this->partitionCachedKeys($request, $bypassCache);

        $resolvedProvider = $provider ?? $this->defaultProvider();

        if ($uncachedRequest->keyCount() === 0) {
            // ── Full cache hit ─────────────────────────────────────────────
            $response = $this->buildCachedOnlyResponse($cachedTranslations, $resolvedProvider);

            $this->persistTranslations($response, $language, $request);
            $log = $this->recordAILog($request, $response, $estimate, $resolvedProvider, source: 'cache');
            $this->dispatchCompletedEvent($log);

            return $response;
        }

        // ── Partial or full API call ───────────────────────────────────────
        $apiResponse = $this->providerManager
            ->driver($provider)
            ->translate($uncachedRequest);

        $this->cacheTranslations($uncachedRequest, $apiResponse);

        $mergedResponse = $this->mergeWithCached($apiResponse, $cachedTranslations, $request);

        $this->persistTranslations($mergedResponse, $language, $request);
        $log = $this->recordAILog($request, $mergedResponse, $estimate, $resolvedProvider, source: 'api');
        $this->dispatchCompletedEvent($log);

        return $mergedResponse;
    }

    // -------------------------------------------------------------------------
    // Cache Partitioning
    // -------------------------------------------------------------------------

    /**
     * Split the request into cached translations and an uncached sub-request.
     *
     * @param  bool  $bypass  When true, treat all keys as uncached regardless of
     *                        what the cache store holds. This replaces the previous
     *                        global config mutation approach.
     * @return array{array<string, string>, TranslationRequest}
     */
    private function partitionCachedKeys(TranslationRequest $request, bool $bypass = false): array
    {
        if ($bypass || ! config('translator.ai.cache.enabled', true)) {
            return [[], $request];
        }

        $cached = [];
        $uncached = [];

        foreach ($request->keys as $key => $sourceValue) {
            $cacheKey = self::buildAICacheKey($request->targetLanguage, $key, $sourceValue);
            $hit = Cache::get($cacheKey);

            if (is_string($hit) && filled($hit)) {
                $cached[$key] = $hit;
            } else {
                $uncached[$key] = $sourceValue;
            }
        }

        $uncachedRequest = new TranslationRequest(
            sourceLanguage: $request->sourceLanguage,
            targetLanguage: $request->targetLanguage,
            keys: $uncached,
            groupName: $request->groupName,
            namespace: $request->namespace,
            preservePlurals: $request->preservePlurals,
            context: $request->context,
        );

        return [$cached, $uncachedRequest];
    }

    /**
     * Build the cache key for an AI-translated value.
     *
     * Public static so that the TranslationObserver can build the same key
     * format when invalidating entries on Translation model events.
     */
    public static function buildAICacheKey(string $targetLocale, string $key, string $sourceValue): string
    {
        $prefix = config('translator.ai.cache.prefix', 'translator_ai');

        return "{$prefix}:{$targetLocale}:{$key}:".md5($sourceValue);
    }

    private function cacheTranslations(TranslationRequest $request, TranslationResponse $response): void
    {
        if (! config('translator.ai.cache.enabled', true)) {
            return;
        }

        $ttl = (int) config('translator.ai.cache.ttl', 86400);

        foreach ($response->translations as $key => $translatedValue) {
            if (! filled($translatedValue)) {
                continue;
            }

            $sourceValue = $request->keys[$key] ?? '';

            Cache::put(
                key: self::buildAICacheKey($request->targetLanguage, $key, $sourceValue),
                value: $translatedValue,
                ttl: $ttl,
            );
        }
    }

    /**
     * @param  array<string, string>  $cachedTranslations
     */
    private function buildCachedOnlyResponse(array $cachedTranslations, string $provider): TranslationResponse
    {
        return new TranslationResponse(
            provider: $provider,
            model: 'cache',
            translations: $cachedTranslations,
            failedKeys: [],
            inputTokensUsed: 0,
            outputTokensUsed: 0,
            actualCostUsd: 0.0,
            durationMs: 0,
        );
    }

    /**
     * @param  array<string, string>  $cachedTranslations
     */
    private function mergeWithCached(
        TranslationResponse $apiResponse,
        array $cachedTranslations,
        TranslationRequest $originalRequest,
    ): TranslationResponse {
        $allTranslations = array_merge($cachedTranslations, $apiResponse->translations);

        $expectedApiKeys = array_diff(array_keys($originalRequest->keys), array_keys($cachedTranslations));
        $failedKeys = array_values(
            array_diff($expectedApiKeys, array_keys($apiResponse->translations)),
        );

        return new TranslationResponse(
            provider: $apiResponse->provider,
            model: $apiResponse->model,
            translations: $allTranslations,
            failedKeys: $failedKeys,
            inputTokensUsed: $apiResponse->inputTokensUsed,
            outputTokensUsed: $apiResponse->outputTokensUsed,
            actualCostUsd: $apiResponse->actualCostUsd,
            durationMs: $apiResponse->durationMs,
        );
    }

    // -------------------------------------------------------------------------
    // Database Persistence
    // -------------------------------------------------------------------------

    /**
     * Persist translated values to the database using a single transaction
     * and a bulk `upsert()`.
     *
     * Key improvements over the previous N+1 pattern:
     *
     * - All reads happen BEFORE the transaction begins (no shared lock contention).
     * - A single `upsert()` replaces both the bulk INSERT and the per-row
     *   `saveQuietly()` calls — one query for all rows.
     * - The `DB::transaction()` wrapper ensures the upsert is atomic: either all
     *   rows are written or none are, preventing partial-update corruption under
     *   concurrent queue workers processing the same language.
     * - On conflict, only `value`, `status`, and `updated_at` are overwritten.
     *   The `created_at` and `translation_key_id` / `language_id` columns remain
     *   stable even when concurrent jobs race on the same key.
     */
    private function persistTranslations(
        TranslationResponse $response,
        Language $language,
        TranslationRequest $request,
    ): void {
        if (empty($response->translations)) {
            return;
        }

        $translationKeyModel = $this->translationKeyModel();
        $translationModel = $this->translationModel();
        $groupModel = $this->groupModel();

        $keys = array_keys($response->translations);

        // Resolve the group outside the transaction (read-only, no contention).
        $group = $groupModel::query()
            ->where('name', $request->groupName)
            ->where('namespace', $request->namespace)
            ->first();

        $keyQuery = $translationKeyModel::query()->whereIn('key', $keys);

        if ($group !== null) {
            $keyQuery->where('group_id', $group->id);
        }

        $keyModels = $keyQuery->get()->keyBy('key');

        $now = now();
        $records = [];

        foreach ($response->translations as $dotKey => $translatedValue) {
            if (! filled($translatedValue)) {
                continue;
            }

            $keyModel = $keyModels->get($dotKey);

            if ($keyModel === null) {
                continue;
            }

            $records[] = [
                'translation_key_id' => $keyModel->id,
                'language_id' => $language->id,
                'value' => $translatedValue,
                'status' => TranslationStatus::Translated->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($records)) {
            return;
        }

        // Single atomic upsert — safe under concurrent workers.
        DB::transaction(static function () use ($translationModel, $records): void {
            $translationModel::query()->upsert(
                $records,
                uniqueBy: ['translation_key_id', 'language_id'],
                update: ['value', 'status', 'updated_at'],
            );
        });
    }

    // -------------------------------------------------------------------------
    // Audit Logging
    // -------------------------------------------------------------------------

    private function recordAILog(
        TranslationRequest $request,
        TranslationResponse $response,
        ?TranslationEstimate $estimate,
        string $provider,
        string $source = 'api',
    ): AITranslationLog {
        /** @var AITranslationLog */
        return AITranslationLog::query()->create([
            'provider' => $provider,
            'model' => $response->model,
            'source_language' => $request->sourceLanguage,
            'target_language' => $request->targetLanguage,
            'group_name' => $request->groupName,
            'key_count' => $request->keyCount(),
            'translated_count' => $response->translatedCount(),
            'failed_count' => count($response->failedKeys),
            'input_tokens_used' => $response->inputTokensUsed,
            'output_tokens_used' => $response->outputTokensUsed,
            'actual_cost_usd' => $response->actualCostUsd,
            'estimated_cost_usd' => $estimate?->estimatedCostUsd ?? 0.0,
            'duration_ms' => $response->durationMs,
            'source' => $source,
            'failed_keys' => $response->failedKeys ?: null,
        ]);
    }

    private function dispatchCompletedEvent(AITranslationLog $log): void
    {
        if (config('translator.events.ai_translation_completed', true)) {
            AITranslationCompleted::dispatch($log);
        }
    }

    private function defaultProvider(): string
    {
        return (string) config('translator.ai.default_provider', 'claude');
    }

    // -------------------------------------------------------------------------
    // Model Resolvers
    // -------------------------------------------------------------------------

    /** @return class-string<Group> */
    private function groupModel(): string
    {
        return config('translator.models.group', Group::class);
    }

    /** @return class-string<TranslationKey> */
    private function translationKeyModel(): string
    {
        return config('translator.models.translation_key', TranslationKey::class);
    }

    /** @return class-string<Translation> */
    private function translationModel(): string
    {
        return config('translator.models.translation', Translation::class);
    }
}
