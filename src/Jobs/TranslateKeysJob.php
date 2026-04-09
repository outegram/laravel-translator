<?php

declare(strict_types=1);

namespace Syriable\Translator\Jobs;

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
 * Stores only serializable primitives — no Eloquent models, no readonly DTOs.
 * TranslationRequest and Language are reconstructed inside handle() from plain
 * data, avoiding every PHP serialization edge case:
 *
 * - SerializesModels::__sleep() tries to replace model instances with
 *   ModelIdentifier objects by doing $this->language = new ModelIdentifier(...).
 *   On a readonly property this throws "Cannot modify readonly property"
 *   and the job is silently dropped — never written to the queue.
 *
 * - readonly class (PHP 8.2) cannot have its properties re-assigned in
 *   __wakeup(), so any DTO declared as `final readonly class` also fails.
 *
 * Storing plain arrays and scalars sidesteps all of this completely.
 */
final class TranslateKeysJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    // Note: SerializesModels is intentionally NOT used here.
    // It calls __sleep() which tries to overwrite readonly properties — this
    // throws "Cannot modify readonly property" and silently drops the job.

    /**
     * Maximum number of attempts before the job is marked as failed.
     */
    public int $tries = 3;

    /**
     * Maximum execution time in seconds before the job is killed.
     */
    public int $timeout = 180;

    /**
     * Plain array representation of TranslationRequest.
     * Stored as an array to avoid readonly class serialization issues.
     *
     * @var array<string, mixed>
     */
    public array $requestData;

    /**
     * The target language ID. Re-fetched from the DB inside handle()
     * so the Language model is never serialized.
     */
    public int $languageId;

    /**
     * @param  TranslationRequest  $request  The translation batch to process.
     * @param  Language  $language  The target language — stored as ID only.
     * @param  string|null  $provider  Provider override, or null for the default.
     */
    public function __construct(
        TranslationRequest $request,
        Language $language,
        public ?string $provider = null,
    ) {
        // Decompose to plain primitives for safe serialization.
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
     * Execute the translation job.
     *
     * Reconstructs the TranslationRequest DTO and re-fetches the Language
     * model from the database — both built fresh from stored primitives.
     */
    public function handle(AITranslationService $service): void
    {
        $language = Language::query()->find($this->languageId);

        if ($language === null) {
            // Language was deleted after the job was dispatched — nothing to do.
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
            );
        } catch (ProviderRateLimitException) {
            // Release with exponential backoff: 60s, 120s, 240s.
            $backoff = 60 * (2 ** ($this->attempts() - 1));
            $this->release($backoff);
        }
    }

    /**
     * @return int[] Delay in seconds for each retry attempt.
     */
    public function backoff(): array
    {
        return [60, 120, 240];
    }
}
