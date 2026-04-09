<?php

declare(strict_types=1);

namespace Syriable\Translator\DTOs\AI;

/**
 * Immutable value object representing the pre-execution cost estimate for a
 * single AI translation request.
 *
 * Produced by TranslationProviderInterface::estimate() and presented to the
 * user for confirmation before any API call is made. This enforces the
 * package's "no execution without cost preview" rule.
 *
 * Token counts are approximations based on character ratios specific to
 * each provider. Actual token usage reported in TranslationResponse may
 * differ slightly due to tokenisation nuances.
 */
final readonly class TranslationEstimate
{
    /**
     * @param  string  $provider  Canonical provider name (e.g. 'claude').
     * @param  string  $model  The model identifier that will be used (e.g. 'claude-opus-4-6').
     * @param  int  $estimatedInputTokens  Approximate tokens for the prompt and source strings.
     * @param  int  $estimatedOutputTokens  Approximate tokens for the expected translated output.
     * @param  float  $estimatedCostUsd  Total estimated cost in US dollars.
     * @param  int  $keyCount  Number of translation keys in the request.
     * @param  int  $sourceCharacters  Total characters of source text being translated.
     */
    public function __construct(
        public string $provider,
        public string $model,
        public int $estimatedInputTokens,
        public int $estimatedOutputTokens,
        public float $estimatedCostUsd,
        public int $keyCount,
        public int $sourceCharacters,
    ) {}

    /**
     * Return the total estimated token count (input + output).
     */
    public function totalEstimatedTokens(): int
    {
        return $this->estimatedInputTokens + $this->estimatedOutputTokens;
    }

    /**
     * Return the estimated cost formatted as a USD string.
     *
     * Example: `'$0.0034'`
     */
    public function formattedCost(): string
    {
        return '$'.number_format($this->estimatedCostUsd, 4);
    }

    /**
     * Return a human-readable summary for CLI display.
     *
     * @return array<array<string, string>> Rows suitable for an Artisan table.
     */
    public function toTableRows(): array
    {
        return [
            ['Provider',          ucfirst($this->provider)],
            ['Model',             $this->model],
            ['Keys to translate', (string) $this->keyCount],
            ['Source characters', number_format($this->sourceCharacters)],
            ['Input tokens',      number_format($this->estimatedInputTokens)],
            ['Output tokens',     number_format($this->estimatedOutputTokens)],
            ['Total tokens',      number_format($this->totalEstimatedTokens())],
            ['Estimated cost',    $this->formattedCost()],
        ];
    }
}
