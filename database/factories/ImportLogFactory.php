<?php

declare(strict_types=1);

namespace Syriable\Translator\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Syriable\Translator\Models\ImportLog;

/**
 * @extends Factory<ImportLog>
 */
final class ImportLogFactory extends Factory
{
    protected $model = ImportLog::class;

    public function definition(): array
    {
        return [
            'locale_count'  => $this->faker->numberBetween(1, 10),
            'key_count'     => $this->faker->numberBetween(10, 500),
            'new_count'     => $this->faker->numberBetween(0, 100),
            'updated_count' => $this->faker->numberBetween(0, 50),
            'duration_ms'   => $this->faker->numberBetween(100, 5000),
            'source'        => $this->faker->randomElement(['cli', 'ui', 'api']),
            'triggered_by'  => null,
            'fresh'         => false,
        ];
    }

    /**
     * Mark the import as a fresh (destructive) run.
     */
    public function fresh(): static
    {
        return $this->state(['fresh' => true]);
    }

    /**
     * Mark the import as triggered from the CLI.
     */
    public function fromCli(): static
    {
        return $this->state(['source' => 'cli']);
    }

    /**
     * Mark the import as triggered from the UI.
     */
    public function fromUi(): static
    {
        return $this->state(['source' => 'ui']);
    }
}