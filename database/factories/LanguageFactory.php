<?php

declare(strict_types=1);

namespace Syriable\Translator\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Syriable\Translator\Models\Language;

/**
 * @extends Factory<Language>
 */
final class LanguageFactory extends Factory
{
    protected $model = Language::class;

    public function definition(): array
    {
        $code = $this->faker->unique()->languageCode();

        return [
            'code' => $code,
            'name' => $this->faker->word(),
            'native_name' => $this->faker->word(),
            'rtl' => false,
            'active' => true,
            'is_source' => false,
        ];
    }

    /** Mark the language as the source language. */
    public function source(): static
    {
        return $this->state(['is_source' => true, 'active' => true]);
    }

    /** Mark the language as inactive. */
    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }

    /** Mark the language as right-to-left. */
    public function rtl(): static
    {
        return $this->state(['rtl' => true]);
    }

    /** Create an English source language. */
    public function english(): static
    {
        return $this->state([
            'code' => 'en',
            'name' => 'English',
            'native_name' => 'English',
            'is_source' => true,
            'active' => true,
            'rtl' => false,
        ]);
    }

    /** Create an Arabic target language. */
    public function arabic(): static
    {
        return $this->state([
            'code' => 'ar',
            'name' => 'Arabic',
            'native_name' => 'العربية',
            'is_source' => false,
            'active' => true,
            'rtl' => true,
        ]);
    }

    /** Create a French target language. */
    public function french(): static
    {
        return $this->state([
            'code' => 'fr',
            'name' => 'French',
            'native_name' => 'Français',
            'is_source' => false,
            'active' => true,
            'rtl' => false,
        ]);
    }
}
