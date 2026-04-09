<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\AI;

use Illuminate\Support\Facades\Cache;
use Syriable\Translator\AI\TranslationProviderManager;
use Syriable\Translator\DTOs\AI\TranslationEstimate;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\DTOs\AI\TranslationResponse;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Models\AITranslationLog;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

/**
 * Orchestrates the AI-powered translation workflow.
 *
 * Enforces the "no execution without cost preview" contract by exposing
 * estimate() and translate() as separate, explicitly ordered operations.
 *
 * Workflow:
 *  1. Caller invokes estimate()  — no API call, returns cost breakdown.
 *  2. User confirms the estimate.
 *  3. Caller invokes translate() with the same request.
 *  4. Cached keys are retrieved — NO API call for those keys.
 *  5. Remaining keys are sent to the provider API.
 *  6. ALL translations (cached + fresh) are persisted and logged.
 */
final readonly class AITranslationService
{
    public function __construct(
        private TranslationProviderManager $providerManager,
    ) {}

    // -------------------------------------------------------------------------
    // Estimation (No API Call)
    // -------------------------------------------------------------------------

    /**
     * Estimate the token usage and cost for the given request.
     *
     * No API call is made. The estimate is deterministic for the same input.
     * Must be presented to the user before translate() is invoked.
     *
     * @param  TranslationRequest  $request  The translation request to estimate.
     * @param  string|null  $provider  Provider override, or null for the default.
     * @return TranslationEstimate Pre-execution cost and token estimate.
     */
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
     * Execute the translation request, persist results, and log the run.
     *
     * Cache behaviour:
     *  - Keys found in cache are reused without an API call (zero token cost).
     *  - Keys NOT in cache are sent to the provider API.
     *  - ALL translated keys (cached + fresh) are persisted to the database.
     *  - A single AITranslationLog is recorded for the entire run.
     *
     * Fix: Previously, a full cache hit returned early and skipped both
     * persistTranslations() and recordAILog(), leaving the database unchanged.
     * Now persistence is always called regardless of the cache outcome.
     *
     * @param  TranslationRequest  $request  The translation request to execute.
     * @param  Language  $language  The target Language model record.
     * @param  string|null  $provider  Provider override, or null for the default.
     * @param  TranslationEstimate|null  $estimate  Pre-execution estimate for cost variance logging.
     * @return TranslationResponse Normalised response with translations and usage stats.
     */
    public function translate(
        TranslationRequest $request,
        Language $language,
        ?string $provider = null,
        ?TranslationEstimate $estimate = null,
    ): TranslationResponse {
        [$cachedTranslations, $uncachedRequest] = $this->partitionCachedKeys($request);

        // Determine the resolved provider name for logging.
        $resolvedProvider = $provider ?? $this->defaultProvider();

        if ($uncachedRequest->keyCount() === 0) {
            // ── Full cache hit ────────────────────────────────────────────────
            // Build a zero-cost response from cache, then persist and log it.
            $response = $this->buildCachedOnlyResponse(
                $cachedTranslations,
                $resolvedProvider,
            );

            $this->persistTranslations($response, $language);
            $this->recordAILog($request, $response, $estimate, $resolvedProvider, source: 'cache');

            return $response;
        }

        // ── Partial or full API call ──────────────────────────────────────────
        $apiResponse = $this->providerManager
            ->driver($provider)
            ->translate($uncachedRequest);

        // Cache the fresh translations for future runs.
        $this->cacheTranslations($uncachedRequest, $apiResponse);

        // Merge cached + fresh translations into one unified response.
        $mergedResponse = $this->mergeWithCached($apiResponse, $cachedTranslations, $request);

        $this->persistTranslations($mergedResponse, $language);
        $this->recordAILog($request, $mergedResponse, $estimate, $resolvedProvider, source: 'api');

        return $mergedResponse;
    }

    // -------------------------------------------------------------------------
    // Cache Partitioning
    // -------------------------------------------------------------------------

    /**
     * Split the request into cached translations and an uncached sub-request.
     *
     * Returns a tuple of:
     *  [0] array<string, string>   — Translations already in cache.
     *  [1] TranslationRequest      — New request containing only uncached keys.
     *
     * When caching is disabled, all keys are returned as uncached.
     *
     *
     * @return array{array<string, string>, TranslationRequest}
     */
    private function partitionCachedKeys(TranslationRequest $request): array
    {
        if (! config('translator.ai.cache.enabled', true)) {
            return [[], $request];
        }

        $cached = [];
        $uncached = [];

        foreach ($request->keys as $key => $sourceValue) {
            $cacheKey = $this->buildCacheKey($request->targetLanguage, $key, $sourceValue);
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
     * Build a deterministic cache key for a single translation.
     *
     * The source value is hashed so cached entries are automatically invalidated
     * when the source string changes — preventing stale translations.
     */
    private function buildCacheKey(string $targetLocale, string $key, string $sourceValue): string
    {
        $prefix = config('translator.ai.cache.prefix', 'translator_ai');

        return "{$prefix}:{$targetLocale}:{$key}:".md5($sourceValue);
    }

    /**
     * Store all successfully translated values in the cache for future reuse.
     */
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
                key: $this->buildCacheKey($request->targetLanguage, $key, $sourceValue),
                value: $translatedValue,
                ttl: $ttl,
            );
        }
    }

    /**
     * Build a zero-cost TranslationResponse from cache-only translations.
     *
     * Used when every key in the request was served from cache.
     *
     * @param  array<string, string>  $cachedTranslations
     */
    private function buildCachedOnlyResponse(
        array $cachedTranslations,
        string $provider,
    ): TranslationResponse {
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
     * Merge an API response with cached translations into a single unified response.
     *
     * The merged response carries the actual token usage from the API call (not
     * inflated by the cached keys) and correctly reports failed keys from the
     * uncached portion only.
     *
     * @param  array<string, string>  $cachedTranslations
     */
    private function mergeWithCached(
        TranslationResponse $apiResponse,
        array $cachedTranslations,
        TranslationRequest $originalRequest,
    ): TranslationResponse {
        $allTranslations = array_merge($cachedTranslations, $apiResponse->translations);

        // Failed keys are only those from the API portion that were not translated.
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
     * Persist translated values to Translation records in the database.
     *
     * Looks up each TranslationKey by its dot-notation key string and updates
     * the corresponding Translation row for the target language.
     *
     * Uses saveQuietly() to suppress model events during bulk AI updates,
     * preventing observer overhead per individual row.
     *
     * Note: If the Translation row does not yet exist (e.g. replicator hasn't
     * run), it is silently skipped. Run translator:import first to seed rows.
     */
    private function persistTranslations(TranslationResponse $response, Language $language): void
    {
        foreach ($response->translations as $dotKey => $translatedValue) {
            if (! filled($translatedValue)) {
                continue;
            }

            $translationKey = TranslationKey::query()
                ->where('key', $dotKey)
                ->first();

            if ($translationKey === null) {
                continue;
            }

            $translation = Translation::query()
                ->where('translation_key_id', $translationKey->id)
                ->where('language_id', $language->id)
                ->first();

            if ($translation === null) {
                // Row doesn't exist yet — create it directly.
                Translation::create([
                    'translation_key_id' => $translationKey->id,
                    'language_id' => $language->id,
                    'value' => $translatedValue,
                    'status' => TranslationStatus::Translated,
                ]);

                continue;
            }

            $translation->fill([
                'value' => $translatedValue,
                'status' => TranslationStatus::Translated,
            ])->saveQuietly();
        }
    }

    // -------------------------------------------------------------------------
    // Audit Logging
    // -------------------------------------------------------------------------

    /**
     * Record the outcome of a translation execution to AITranslationLog.
     *
     * Called for both cache hits and API calls, enabling cost variance analysis
     * and cache effectiveness tracking over time.
     *
     * @param  TranslationRequest  $request  Original request metadata.
     * @param  TranslationResponse  $response  Execution result.
     * @param  TranslationEstimate|null  $estimate  Pre-execution estimate, if available.
     * @param  string  $provider  Resolved provider name.
     * @param  string  $source  Execution source: 'api' or 'cache'.
     */
    private function recordAILog(
        TranslationRequest $request,
        TranslationResponse $response,
        ?TranslationEstimate $estimate,
        string $provider,
        string $source = 'api',
    ): void {
        AITranslationLog::query()->create([
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

    /**
     * Resolve the default provider name from configuration.
     */
    private function defaultProvider(): string
    {
        return (string) config('translator.ai.default_provider', 'claude');
    }
}
