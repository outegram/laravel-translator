<?php

declare(strict_types=1);

namespace Syriable\Translator\Contracts;

use Syriable\Translator\DTOs\AI\TranslationEstimate;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\DTOs\AI\TranslationResponse;
use Syriable\Translator\Models\Language;

/**
 * Public contract for the AI translation service.
 *
 * Companion packages and application code that drive AI translation
 * programmatically should type-hint against this interface.
 *
 * The two-step contract enforces the "no execution without cost preview" rule
 * at the type level: callers must call estimate() first, present the result
 * to the user, and only then call translate().
 *
 * @see \Syriable\Translator\Services\AI\AITranslationService
 */
interface AITranslationServiceContract
{
    /**
     * Estimate the token usage and cost for the given request without any API call.
     *
     * The estimate is deterministic for the same input. It must be presented to
     * the user before translate() is invoked.
     *
     * @param  TranslationRequest  $request  The translation request to estimate.
     * @param  string|null  $provider  Provider override, or null for the default.
     * @return TranslationEstimate Pre-execution cost and token estimate.
     */
    public function estimate(TranslationRequest $request, ?string $provider = null): TranslationEstimate;

    /**
     * Execute the translation request, persist results, log the run, and
     * dispatch the AITranslationCompleted event.
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
    ): TranslationResponse;
}
