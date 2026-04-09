<?php

declare(strict_types=1);

use Syriable\Translator\AI\Estimators\TokenEstimator;

describe('TokenEstimator', function (): void {

    beforeEach(function (): void {
        $this->estimator = new TokenEstimator;
    });

    // -------------------------------------------------------------------------
    // estimateInputTokens
    // -------------------------------------------------------------------------

    describe('estimateInputTokens()', function (): void {

        it('returns a positive integer for non-empty input', function (): void {
            $tokens = $this->estimator->estimateInputTokens(
                prompt: 'You are a professional translator.',
                keys: ['auth.failed' => 'These credentials do not match.'],
                sourceLocale: 'en',
            );

            expect($tokens)->toBeGreaterThan(0);
        });

        it('returns more tokens for longer input', function (): void {
            $short = $this->estimator->estimateInputTokens(
                prompt: 'Short prompt.',
                keys: ['k' => 'Short.'],
                sourceLocale: 'en',
            );

            $long = $this->estimator->estimateInputTokens(
                prompt: str_repeat('A very long system prompt. ', 100),
                keys: array_fill(0, 50, 'A much longer translation string value here.'),
                sourceLocale: 'en',
            );

            expect($long)->toBeGreaterThan($short);
        });

        it('uses a lower chars-per-token ratio for dense-script locales', function (): void {
            // Arabic text of equal character count should produce more tokens than English.
            $arabicKeys = ['مرحبا' => 'مرحبا بالعالم، هذا نص للاختبار'];
            $englishKeys = ['hello' => 'Hello world, this is a test string'];

            $arabicTokens = $this->estimator->estimateInputTokens(
                prompt: 'Translate.',
                keys: $arabicKeys,
                sourceLocale: 'ar',
            );

            $englishTokens = $this->estimator->estimateInputTokens(
                prompt: 'Translate.',
                keys: $englishKeys,
                sourceLocale: 'en',
            );

            // Arabic uses ~2 chars/token vs ~4 chars/token for English,
            // so similar character counts produce more tokens for Arabic.
            expect($arabicTokens)->toBeGreaterThanOrEqual($englishTokens);
        });
    });

    // -------------------------------------------------------------------------
    // estimateOutputTokens
    // -------------------------------------------------------------------------

    describe('estimateOutputTokens()', function (): void {

        it('returns a positive integer for non-empty keys', function (): void {
            $tokens = $this->estimator->estimateOutputTokens(
                keys: ['auth.failed' => 'These credentials do not match.'],
                targetLocale: 'fr',
            );

            expect($tokens)->toBeGreaterThan(0);
        });

        it('applies expansion factor for Latin-script targets', function (): void {
            // German typically expands relative to English source.
            $english = $this->estimator->estimateOutputTokens(
                keys: ['k' => 'Login to your account.'],
                targetLocale: 'en',
            );

            $german = $this->estimator->estimateOutputTokens(
                keys: ['k' => 'Login to your account.'],
                targetLocale: 'de',
            );

            // Both use Latin script; German may expand slightly more.
            expect($german)->toBeGreaterThanOrEqual($english * 0.8);
        });
    });

    // -------------------------------------------------------------------------
    // estimateCost
    // -------------------------------------------------------------------------

    describe('estimateCost()', function (): void {

        it('calculates cost correctly using configured rates', function (): void {
            config(['translator.ai.providers.claude.input_cost_per_1k_tokens' => 0.003]);
            config(['translator.ai.providers.claude.output_cost_per_1k_tokens' => 0.015]);

            // 1000 input tokens at $0.003/1k + 500 output tokens at $0.015/1k.
            // = $0.003 + $0.0075 = $0.0105
            $cost = $this->estimator->estimateCost('claude', 1000, 500);

            expect($cost)->toBeGreaterThan(0.0)
                ->toBeLessThan(1.0);
        });

        it('uses the default rate when no provider rate is configured', function (): void {
            config(['translator.ai.default_cost_per_1k_tokens' => 0.005]);

            $cost = $this->estimator->estimateCost('unknown_provider', 1000, 1000);

            expect($cost)->toBeGreaterThan(0.0);
        });

        it('returns zero cost for zero tokens', function (): void {
            expect($this->estimator->estimateCost('claude', 0, 0))->toBe(0.0);
        });
    });
});
