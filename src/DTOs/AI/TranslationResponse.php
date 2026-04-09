<?php

declare(strict_types=1);

namespace Syriable\Translator\DTOs\AI;

/**
 * Immutable value object representing the normalised result of a completed
 * AI translation request.
 *
 * Produced by TranslationProviderInterface::translate() after a successful
 * API call. Contains the translated key-value pairs, actual token usage,
 * and any keys the provider was unable to translate.
 */
final readonly class TranslationResponse
{
    /**
     * @param  string  $provider  Canonical provider name (e.g. 'claude').
     * @param  string  $model  The model that was actually used.
     * @param  array<string, string>  $translations  Map of translation key => translated string.
     * @param  string[]  $failedKeys  Keys that could not be translated (returned empty or errored).
     * @param  int  $inputTokensUsed  Actual input tokens consumed by the API.
     * @param  int  $outputTokensUsed  Actual output tokens consumed by the API.
     * @param  float  $actualCostUsd  Actual cost in USD based on reported token usage.
     * @param  int  $durationMs  Wall-clock time for the API call in milliseconds.
     */
    public function __construct(
        public string $provider,
        public string $model,
        public array $translations,
        public array $failedKeys,
        public int $inputTokensUsed,
        public int $outputTokensUsed,
        public float $actualCostUsd,
        public int $durationMs,
    ) {}

    /**
     * Return the total tokens consumed (input + output).
     */
    public function totalTokensUsed(): int
    {
        return $this->inputTokensUsed + $this->outputTokensUsed;
    }

    /**
     * Determine whether all requested keys were successfully translated.
     */
    public function isComplete(): bool
    {
        return empty($this->failedKeys);
    }

    /**
     * Return the number of keys successfully translated.
     */
    public function translatedCount(): int
    {
        return count($this->translations);
    }

    /**
     * Return the actual cost formatted as a USD string.
     *
     * Example: `'$0.0041'`
     */
    public function formattedCost(): string
    {
        return '$'.number_format($this->actualCostUsd, 4);
    }
}
