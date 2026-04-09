<?php

declare(strict_types=1);

namespace Syriable\Translator\AI\Estimators;

/**
 * Approximates token counts for AI provider requests without calling any API.
 *
 * Token estimation is inherently imprecise — different tokenisers split text
 * differently based on language, punctuation, and script. The ratios used here
 * are calibrated for multilingual translation workloads:
 *
 *  - Latin scripts:  ~4 characters per token (English, French, German, etc.)
 *  - RTL / complex:  ~2 characters per token (Arabic, Hebrew, Chinese, etc.)
 *  - Mixed:          ~3 characters per token (default)
 *
 * These ratios are configurable via `translator.ai.token_estimation` to allow
 * tuning as real usage data accumulates.
 *
 * Actual token usage reported in TranslationResponse may differ by ±15%.
 */
final class TokenEstimator
{
    /**
     * BCP 47 locale codes that use RTL or logographic scripts requiring
     * a lower characters-per-token ratio.
     *
     * @var string[]
     */
    private const array DENSE_SCRIPT_LOCALES = [
        'ar', 'he', 'fa', 'ur', 'ps', 'sd', 'yi',
        'zh', 'zh-Hant', 'ja', 'ko',
        'hi', 'bn', 'gu', 'pa', 'mr', 'ne', 'ta', 'te', 'kn', 'ml',
        'th', 'km', 'lo', 'my',
    ];

    /**
     * Approximate the number of tokens for a given text and target locale.
     *
     * Applies a characters-per-token ratio calibrated to the target script.
     * Overhead accounts for JSON structure, key names, and XML prompt framing.
     *
     * @param  string  $text  The text to estimate tokens for.
     * @param  string  $targetLocale  BCP 47 locale code of the target language.
     * @param  int  $overhead  Additional tokens to add for structural overhead.
     */
    public function estimateForText(string $text, string $targetLocale, int $overhead = 0): int
    {
        $ratio = $this->charsPerToken($targetLocale);
        $characters = mb_strlen($text);

        return (int) ceil($characters / $ratio) + $overhead;
    }

    /**
     * Approximate the total input token count for a translation request prompt.
     *
     * Input tokens include:
     *  - The system prompt (fixed overhead, read from config).
     *  - The XML request framing (source language, target language, group name).
     *  - All source string values.
     *  - All key names.
     *
     * @param  string  $prompt  The fully rendered prompt string.
     * @param  array<string, string>  $keys  The translation key-value pairs.
     * @param  string  $sourceLocale  Source locale for ratio calculation.
     */
    public function estimateInputTokens(string $prompt, array $keys, string $sourceLocale): int
    {
        $promptChars = mb_strlen($prompt);
        $keyChars = (int) array_sum(array_map(mb_strlen(...), array_keys($keys)));
        $valueChars = (int) array_sum(array_map(mb_strlen(...), array_values($keys)));

        $ratio = $this->charsPerToken($sourceLocale);

        return (int) ceil(($promptChars + $keyChars + $valueChars) / $ratio);
    }

    /**
     * Approximate the total output token count for expected translated strings.
     *
     * Output is estimated at the same character count as the input values,
     * multiplied by a per-locale expansion factor. Languages like German or
     * Finnish often expand significantly; CJK scripts tend to be more compact.
     *
     * @param  array<string, string>  $keys  The translation key-value pairs.
     * @param  string  $targetLocale  Target locale for ratio and expansion factor.
     */
    public function estimateOutputTokens(array $keys, string $targetLocale): int
    {
        $valueChars = (int) array_sum(array_map(mb_strlen(...), array_values($keys)));

        // Account for structural overhead: key names repeated in JSON output + quotes + commas.
        $keyChars = (int) array_sum(array_map(mb_strlen(...), array_keys($keys)));
        $structuralOverhead = (int) ceil($keyChars * 0.5);

        $expandedChars = (int) ceil($valueChars * $this->expansionFactor($targetLocale));
        $ratio = $this->charsPerToken($targetLocale);

        return (int) ceil(($expandedChars + $structuralOverhead) / $ratio);
    }

    /**
     * Calculate the estimated cost in USD for the given token counts and provider.
     *
     * Rates are read from `translator.ai.providers.{provider}.cost_per_1k_tokens`.
     * Falls back to the default rate when no provider-specific rate is configured.
     *
     * @param  string  $provider  Canonical provider name (e.g. 'claude').
     * @param  int  $inputTokens  Estimated input token count.
     * @param  int  $outputTokens  Estimated output token count.
     */
    public function estimateCost(string $provider, int $inputTokens, int $outputTokens): float
    {
        $inputRate = $this->resolveRate($provider, 'input_cost_per_1k_tokens');
        $outputRate = $this->resolveRate($provider, 'output_cost_per_1k_tokens');

        $inputCost = ($inputTokens / 1000) * $inputRate;
        $outputCost = ($outputTokens / 1000) * $outputRate;

        return round($inputCost + $outputCost, 6);
    }

    /**
     * Resolve a pricing rate from configuration for the given provider and rate key.
     *
     * Falls back to the configured default rate when the provider key is absent.
     *
     * @param  string  $provider  Canonical provider name.
     * @param  string  $rateKey  Rate configuration key (e.g. 'input_cost_per_1k_tokens').
     */
    private function resolveRate(string $provider, string $rateKey): float
    {
        $providerRate = config("translator.ai.providers.{$provider}.{$rateKey}");

        if ($providerRate !== null) {
            return (float) $providerRate;
        }

        return (float) config('translator.ai.default_cost_per_1k_tokens', 0.005);
    }

    /**
     * Return the characters-per-token ratio for the given locale.
     *
     * Dense-script locales (RTL, CJK, Indic) pack more meaning per token
     * than Latin-alphabet locales, so they require fewer characters per token.
     */
    private function charsPerToken(string $locale): float
    {
        $configured = config("translator.ai.token_estimation.chars_per_token.{$locale}");

        if ($configured !== null) {
            return (float) $configured;
        }

        if (in_array($locale, self::DENSE_SCRIPT_LOCALES, strict: true)) {
            return (float) config('translator.ai.token_estimation.dense_script_ratio', 2.0);
        }

        return (float) config('translator.ai.token_estimation.default_ratio', 4.0);
    }

    /**
     * Return the expected character expansion factor when translating into the target locale.
     *
     * Some languages expand significantly relative to English source text
     * (e.g. German +20-35%, Finnish +30%). CJK scripts typically contract.
     * The default factor of 1.2 covers most Western language expansions.
     */
    private function expansionFactor(string $targetLocale): float
    {
        $configured = config("translator.ai.token_estimation.expansion_factors.{$targetLocale}");

        if ($configured !== null) {
            return (float) $configured;
        }

        // CJK languages are typically more compact than English.
        $compactLocales = ['zh', 'zh-Hant', 'ja', 'ko'];

        if (in_array($targetLocale, $compactLocales, strict: true)) {
            return 0.8;
        }

        return (float) config('translator.ai.token_estimation.default_expansion_factor', 1.2);
    }
}
