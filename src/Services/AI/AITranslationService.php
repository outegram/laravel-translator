<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\AI;

use Illuminate\Support\Facades\Cache;
use Syriable\Translator\AI\TranslationProviderManager;
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
 *  7. AITranslationCompleted event is dispatched (when enabled in config).
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
     * Execute the translation request, persist results, log the run, and
     * dispatch the AITranslationCompleted event.
     *
     * Cache behaviour:
     *  - Keys found in cache are reused without an API call (zero token cost).
     *  - Keys NOT in cache are sent to the provider API.
     *  - ALL translated keys (cached + fresh) are persisted to the database.
     *  - A single AITranslationLog is recorded for the entire run.
     *  - AITranslationCompleted is dispatched when the config flag is true.
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

        $resolvedProvider = $provider ?? $this->defaultProvider();

        if ($uncachedRequest->keyCount() === 0) {
            // ── Full cache hit ────────────────────────────────────────────────
            $response = $this->buildCachedOnlyResponse($cachedTranslations, $resolvedProvider);

            $this->persistTranslations($response, $language, $request);
            $log = $this->recordAILog($request, $response, $estimate, $resolvedProvider, source: 'cache');
            $this->dispatchCompletedEvent($log);

            return $response;
        }

        // ── Partial or full API call ──────────────────────────────────────────
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

    private function buildCacheKey(string $targetLocale, string $key, string $sourceValue): string
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
                key: $this->buildCacheKey($request->targetLanguage, $key, $sourceValue),
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
     * Persist translated values to Translation records in the database.
     *
     * Uses a bulk pre-load strategy to avoid N+1 queries:
     *  - One query to resolve the Group record for the request (prevents cross-group collisions).
     *  - One query to load all matching TranslationKey records scoped by group_id.
     *  - One query to load all existing Translation rows for the target language.
     *  - One bulk INSERT for all new rows.
     *  - Individual saveQuietly() calls only for existing rows that need updating.
     *
     * All model classes are resolved from config('translator.models.*') to support
     * application-level model overrides.
     *
     * ### Group scoping
     *
     * When `$request->groupName` maps to a real Group record, keys are looked up
     * scoped by `group_id`. This prevents silent data corruption when the same key
     * name exists in multiple groups (e.g. 'failed' in 'auth' and 'passwords').
     *
     * When the group name does not correspond to a database record (e.g. the
     * sentinel value '_all' used by AITranslateCommand for cross-group batches),
     * scoping by group_id is skipped and keys are matched by name alone — preserving
     * the original behaviour for that call path.
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

        // Try to resolve the group so we can scope the key lookup by group_id.
        // When the group name is a sentinel (e.g. '_all') or refers to a group that
        // does not yet exist, $group will be null and we fall back to name-only lookup.
        $group = $groupModel::query()
            ->where('name', $request->groupName)
            ->where('namespace', $request->namespace)
            ->first();

        // Build the TranslationKey collection — scoped when a real group is resolved,
        // unscoped (by name only) when the request spans multiple groups.
        $keyQuery = $translationKeyModel::query()->whereIn('key', $keys);

        if ($group !== null) {
            $keyQuery->where('group_id', $group->id);
        }

        $keyModels = $keyQuery->get()->keyBy('key');

        // Bulk load all existing Translation rows for this language — 1 query.
        $existingTranslations = $translationModel::query()
            ->whereIn('translation_key_id', $keyModels->pluck('id'))
            ->where('language_id', $language->id)
            ->get()
            ->keyBy('translation_key_id');

        $toInsert = [];
        $now = now();

        foreach ($response->translations as $dotKey => $translatedValue) {
            if (! filled($translatedValue)) {
                continue;
            }

            $keyModel = $keyModels->get($dotKey);

            if ($keyModel === null) {
                continue;
            }

            $existing = $existingTranslations->get($keyModel->id);

            if ($existing === null) {
                // Queue for bulk insert.
                $toInsert[] = [
                    'translation_key_id' => $keyModel->id,
                    'language_id' => $language->id,
                    'value' => $translatedValue,
                    'status' => TranslationStatus::Translated->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            } else {
                $existing->fill([
                    'value' => $translatedValue,
                    'status' => TranslationStatus::Translated,
                ])->saveQuietly();
            }
        }

        // Single bulk insert for all new rows — 1 query.
        if (! empty($toInsert)) {
            $translationModel::query()->insert($toInsert);
        }
    }

    // -------------------------------------------------------------------------
    // Audit Logging
    // -------------------------------------------------------------------------

    /**
     * Record the outcome of a translation execution to AITranslationLog.
     *
     * Returns the persisted log record so it can be passed to the event.
     */
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

    /**
     * Dispatch the AITranslationCompleted event when enabled in config.
     */
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
