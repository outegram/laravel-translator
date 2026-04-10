<?php

declare(strict_types=1);

namespace Syriable\Translator\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Syriable\Translator\Models\AITranslationLog;

/**
 * @extends Factory<AITranslationLog>
 */
final class AITranslationLogFactory extends Factory
{
    protected $model = AITranslationLog::class;

    public function definition(): array
    {
        $keys = $this->faker->numberBetween(5, 100);
        $translated = $this->faker->numberBetween(0, $keys);
        $failed = $keys - $translated;
        $inputTokens = $this->faker->numberBetween(200, 5000);
        $outputTokens = (int) ($inputTokens * 0.5);

        return [
            'provider' => $this->faker->randomElement(['claude', 'chatgpt']),
            'model' => 'claude-haiku-4-5-20251001',
            'source_language' => 'en',
            'target_language' => $this->faker->languageCode(),
            'group_name' => $this->faker->word(),
            'key_count' => $keys,
            'translated_count' => $translated,
            'failed_count' => $failed,
            'input_tokens_used' => $inputTokens,
            'output_tokens_used' => $outputTokens,
            'actual_cost_usd' => round(($inputTokens + $outputTokens) / 1000 * 0.003, 6),
            'estimated_cost_usd' => round(($inputTokens + $outputTokens) / 1000 * 0.003, 6),
            'duration_ms' => $this->faker->numberBetween(500, 10000),
            'source' => 'cli',
            'triggered_by' => null,
            'failed_keys' => $failed > 0 ? ['some.failed.key'] : null,
        ];
    }
}
