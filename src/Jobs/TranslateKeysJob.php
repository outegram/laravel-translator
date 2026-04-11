<?php

declare(strict_types=1);

namespace Syriable\Translator\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\Exceptions\AI\ProviderRateLimitException;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Services\AI\AITranslationService;

/**
 * Queued job that executes a single AI translation request batch.
 *
 * Key changes in this version:
 *
 * 1. `Batchable` trait added: enables Bus::batch() support and provides
 *    `$this->batch()` for cancellation checks. Horizon can now track progress
 *    at the batch level, not just individual job level.
 *
 * 2. `$bypassCache` field: carries the `--fresh-cache` flag from the command
 *    into each job without mutating global config. The flag is passed through
 *    to AITranslationService::translate() as an explicit parameter.
 *
 * 3. Static `make()` factory: provides a clean construction API that handles
 *    the DTO-to-primitive decomposition in one place.
 *
 * 4. Batch cancellation guard: checks if the parent batch was cancelled before
 *    making any API call, preventing wasted requests when a batch fails early.
 *
 * NOTE: SerializesModels is intentionally NOT used. It calls __sleep() which
 * tries to reassign readonly properties — this throws "Cannot modify readonly
 * property" and silently drops the job from the queue.
 */
final class TranslateKeysJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    /**
     * Plain array representation of TranslationRequest.
     * Stored as primitives to avoid readonly class serialization issues.
     *
     * @var array<string, mixed>
     */
    public array $requestData;

    /** Target Language ID — re-fetched from DB in handle() to avoid SerializesModels. */
    public int $languageId;

    public function __construct(
        TranslationRequest $request,
        Language $language,
        public ?string $provider = null,
        public bool $bypassCache = false,
    ) {
        $this->requestData = [
            'sourceLanguage' => $request->sourceLanguage,
            'targetLanguage' => $request->targetLanguage,
            'keys' => $request->keys,
            'groupName' => $request->groupName,
            'namespace' => $request->namespace,
            'preservePlurals' => $request->preservePlurals,
            'context' => $request->context,
        ];

        $this->languageId = $language->id;
    }

    /**
     * Static factory for readable construction at dispatch sites.
     *
     * Usage:
     *   TranslateKeysJob::make($request, $language, $provider, $bypassCache)
     *       ->onQueue('translations');
     */
    public static function make(
        TranslationRequest $request,
        Language $language,
        ?string $provider = null,
        bool $bypassCache = false,
    ): self {
        return new self($request, $language, $provider, $bypassCache);
    }

    /**
     * Execute the translation job.
     *
     * Checks for batch cancellation first — if the parent batch was cancelled
     * (e.g. due to a fatal failure in a sibling job), skip the API call entirely.
     */
    public function handle(AITranslationService $service): void
    {
        // Honour batch cancellation: if another job in the batch failed fatally,
        // skip the API call rather than wasting tokens on a cancelled run.
        if ($this->batch()?->cancelled()) {
            return;
        }

        /** @var Language|null $language */
        $language = Language::query()->find($this->languageId);

        if ($language === null) {
            // Language was deleted after dispatch — nothing to do.
            return;
        }

        $request = new TranslationRequest(
            sourceLanguage: $this->requestData['sourceLanguage'],
            targetLanguage: $this->requestData['targetLanguage'],
            keys: $this->requestData['keys'],
            groupName: $this->requestData['groupName'],
            namespace: $this->requestData['namespace'] ?? null,
            preservePlurals: $this->requestData['preservePlurals'] ?? true,
            context: $this->requestData['context'] ?? null,
        );

        try {
            $service->translate(
                request: $request,
                language: $language,
                provider: $this->provider,
                estimate: null,
                bypassCache: $this->bypassCache,
            );
        } catch (ProviderRateLimitException) {
            // Release with exponential backoff: 60s, 120s, 240s.
            $backoff = 60 * (2 ** ($this->attempts() - 1));
            $this->release($backoff);
        }
    }

    /** @return int[] Delay in seconds for each retry attempt. */
    public function backoff(): array
    {
        return [60, 120, 240];
    }
}
