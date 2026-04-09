<?php

declare(strict_types=1);

namespace Syriable\Translator\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\TranslationKey;

/**
 * @extends Factory<TranslationKey>
 */
final class TranslationKeyFactory extends Factory
{
    protected $model = TranslationKey::class;

    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'key' => $this->faker->unique()->slug(3, '.'),
            'parameters' => null,
            'is_html' => false,
            'is_plural' => false,
        ];
    }

    /** Mark the key as having the given parameters. */
    public function withParameters(array $parameters): static
    {
        return $this->state(['parameters' => $parameters]);
    }

    /** Mark the key as containing inline HTML. */
    public function html(): static
    {
        return $this->state(['is_html' => true]);
    }

    /** Mark the key as using plural pipe syntax. */
    public function plural(): static
    {
        return $this->state(['is_plural' => true]);
    }

    /** Create a key with a colon-prefixed parameter token. */
    public function withColonParam(string $param = ':name'): static
    {
        return $this->state(['parameters' => [$param]]);
    }
}
