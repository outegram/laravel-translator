<?php

declare(strict_types=1);

namespace Syriable\Translator\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Syriable\Translator\Models\Group;

/**
 * @extends Factory<Group>
 */
final class GroupFactory extends Factory
{
    protected $model = Group::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('group_####'),
            'namespace' => null,
            'file_format' => 'php',
            'file_path' => null,
        ];
    }

    /** Create a JSON group (the _json sentinel group). */
    public function json(): static
    {
        return $this->state([
            'name' => Group::JSON_GROUP_NAME,
            'namespace' => null,
            'file_format' => 'json',
        ]);
    }

    /** Create a vendor-namespaced group. */
    public function vendor(string $namespace = 'spatie'): static
    {
        return $this->state([
            'namespace' => $namespace,
            'file_format' => 'php',
        ]);
    }

    /** Create the standard 'auth' group. */
    public function auth(): static
    {
        return $this->state(['name' => 'auth', 'file_format' => 'php']);
    }

    /** Create the standard 'validation' group. */
    public function validation(): static
    {
        return $this->state(['name' => 'validation', 'file_format' => 'php']);
    }
}
