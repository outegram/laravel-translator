<?php

declare(strict_types=1);

use Syriable\Translator\Models\AITranslationLog;

describe('AITranslationLog', function (): void {

    beforeEach(function (): void {
        $this->log = AITranslationLog::factory()->create([
            'provider' => 'claude',
            'model' => 'claude-sonnet-4-6',
            'source_language' => 'en',
            'target_language' => 'ar',
            'key_count' => 10,
            'translated_count' => 9,
            'failed_count' => 1,
            'input_tokens_used' => 1200,
            'output_tokens_used' => 600,
            'actual_cost_usd' => 0.0126,
            'estimated_cost_usd' => 0.0120,
            'duration_ms' => 3400,
        ]);
    });

    it('calculates totalTokensUsed correctly', function (): void {
        expect($this->log->totalTokensUsed())->toBe(1800);
    });

    it('formats actual cost as a dollar string', function (): void {
        expect($this->log->formattedActualCost())->toBe('$0.0126');
    });

    it('formats estimated cost as a dollar string', function (): void {
        expect($this->log->formattedEstimatedCost())->toBe('$0.0120');
    });

    it('calculates cost variance as a percentage', function (): void {
        // ($0.0126 - $0.0120) / $0.0120 * 100 = +5%
        expect($this->log->costVariancePercent())->toBe(5.0);
    });

    it('returns zero variance when estimated cost is zero', function (): void {
        $this->log->estimated_cost_usd = 0.0;

        expect($this->log->costVariancePercent())->toBe(0.0);
    });

    it('reports hadFailures() correctly', function (): void {
        expect($this->log->hadFailures())->toBeTrue();

        $this->log->failed_count = 0;
        expect($this->log->hadFailures())->toBeFalse();
    });

    it('calculates successRate() as a percentage', function (): void {
        // 9 translated out of 10 = 90%
        expect($this->log->successRate())->toBe(90.0);
    });

    it('returns 100% success rate when key_count is zero', function (): void {
        $this->log->key_count = 0;

        expect($this->log->successRate())->toBe(100.0);
    });

    it('filters by provider via forProvider scope', function (): void {
        AITranslationLog::factory()->create(['provider' => 'chatgpt']);

        $claudeLogs = AITranslationLog::query()->forProvider('claude')->get();

        expect($claudeLogs)->toHaveCount(1)
            ->and($claudeLogs->first()->provider)->toBe('claude');
    });

    it('filters by language pair via forLanguagePair scope', function (): void {
        AITranslationLog::factory()->create([
            'source_language' => 'en',
            'target_language' => 'fr',
        ]);

        $arLogs = AITranslationLog::query()->forLanguagePair('en', 'ar')->get();

        expect($arLogs)->toHaveCount(1)
            ->and($arLogs->first()->target_language)->toBe('ar');
    });

    it('filters runs with failures via withFailures scope', function (): void {
        AITranslationLog::factory()->create(['failed_count' => 0]);

        $withFailures = AITranslationLog::query()->withFailures()->get();

        expect($withFailures)->toHaveCount(1)
            ->and($withFailures->first()->failed_count)->toBeGreaterThan(0);
    });
});
