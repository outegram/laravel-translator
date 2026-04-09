<?php

declare(strict_types=1);

use Syriable\Translator\DTOs\AI\TranslationEstimate;

describe('TranslationEstimate', function (): void {

    beforeEach(function (): void {
        $this->estimate = new TranslationEstimate(
            provider: 'claude',
            model: 'claude-sonnet-4-6',
            estimatedInputTokens: 1500,
            estimatedOutputTokens: 800,
            estimatedCostUsd: 0.0165,
            keyCount: 12,
            sourceCharacters: 480,
        );
    });

    it('calculates total estimated tokens as input + output', function (): void {
        expect($this->estimate->totalEstimatedTokens())->toBe(2300);
    });

    it('formats cost as a dollar string with 4 decimal places', function (): void {
        expect($this->estimate->formattedCost())->toBe('$0.0165');
    });

    it('toTableRows() returns an array with 8 rows', function (): void {
        $rows = $this->estimate->toTableRows();

        expect($rows)->toHaveCount(8)
            ->toBeArray();
    });

    it('toTableRows() includes the provider name', function (): void {
        $rows = $this->estimate->toTableRows();

        $labels = array_column($rows, 0);
        expect($labels)->toContain('Provider');
    });

    it('toTableRows() includes the estimated cost', function (): void {
        $rows = $this->estimate->toTableRows();

        $costRow = collect($rows)->firstWhere(0, 'Estimated cost');
        expect($costRow[1])->toBe('$0.0165');
    });

    it('formats a zero cost correctly', function (): void {
        $zero = new TranslationEstimate('claude', 'claude-sonnet-4-6', 0, 0, 0.0, 0, 0);

        expect($zero->formattedCost())->toBe('$0.0000');
    });
});
