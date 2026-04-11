<?php

declare(strict_types=1);

namespace Syriable\Translator\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Syriable\Translator\Models\ExportLog;

/**
 * @extends Factory<ExportLog>
 */
final class ExportLogFactory extends Factory
{
    protected $model = ExportLog::class;

    public function definition(): array
    {
        return [
            'locale_count' => $this->faker->numberBetween(1, 10),
            'file_count' => $this->faker->numberBetween(1, 50),
            'key_count' => $this->faker->numberBetween(10, 500),
            'duration_ms' => $this->faker->numberBetween(50, 3000),
            'source' => $this->faker->randomElement(['cli', 'ui', 'api']),
            'triggered_by' => null,
        ];
    }

    /**
     * Mark the export as triggered from the CLI.
     */
    public function fromCli(): static
    {
        return $this->state(['source' => 'cli']);
    }

    /**
     * Mark the export as triggered from the UI.
     */
    public function fromUi(): static
    {
        return $this->state(['source' => 'ui']);
    }
}
