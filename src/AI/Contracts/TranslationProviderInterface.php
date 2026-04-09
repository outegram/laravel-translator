<?php

declare(strict_types=1);

namespace Syriable\Translator\AI\Contracts;

use Syriable\Translator\DTOs\AI\TranslationEstimate;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\DTOs\AI\TranslationResponse;

/**
 * Contract that every AI translation provider must fulfil.
 *
 * Implementors are responsible for:
 *  - Estimating token usage and cost before execution.
 *  - Communicating with the underlying AI API.
 *  - Normalising raw API responses into a TranslationResponse.
 *
 * The separation of estimate() from translate() enforces the package's
 * "no execution without cost preview" rule at the architectural level —
 * callers must always call estimate() first and present the result to the
 * user before invoking translate().
 *
 * @see \Syriable\Translator\AI\Drivers\ClaudeDriver
 */
interface TranslationProviderInterface
{
    /**
     * Estimate the token usage and cost for the given translation request
     * WITHOUT making any API call.
     *
     * The estimate is based purely on character counts and provider-specific
     * pricing rates read from configuration. It must be deterministic for the
     * same input and must not produce side effects.
     *
     * @param  TranslationRequest  $request  The translation request to estimate.
     * @return TranslationEstimate Estimated token counts and cost.
     */
    public function estimate(TranslationRequest $request): TranslationEstimate;

    /**
     * Execute the translation request against the AI provider's API.
     *
     * Implementors must:
     *  - Build a provider-appropriate prompt from the request.
     *  - Handle HTTP communication and retries.
     *  - Parse and normalise the API response.
     *  - Record actual token usage in the returned response.
     *
     * @param  TranslationRequest  $request  The translation request to execute.
     * @return TranslationResponse Normalised response with translated values and usage.
     */
    public function translate(TranslationRequest $request): TranslationResponse;

    /**
     * Return the canonical provider identifier used in configuration and logs.
     *
     * Must match a key in `translator.ai.providers.*`.
     * Example: 'claude', 'chatgpt', 'gemini'.
     */
    public function providerName(): string;

    /**
     * Determine whether this provider is correctly configured and reachable.
     *
     * Used as a health check before executing translation batches. Should
     * verify that the API key is present and the endpoint is reachable
     * without performing a full translation request.
     */
    public function isAvailable(): bool;
}
