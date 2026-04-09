<?php

declare(strict_types=1);

namespace Syriable\Translator\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

/**
 * @extends Factory<Translation>
 */
final class TranslationFactory extends Factory
{
    protected $model = Translation::class;

    public function definition(): array
    {
        return [
            'translation_key_id' => TranslationKey::factory(),
            'language_id' => Language::factory(),
            'value' => null,
            'status' => TranslationStatus::Untranslated,
        ];
    }

    /** Mark the translation as translated with an optional value. */
    public function translated(?string $value = null): static
    {
        return $this->state([
            'value' => $value ?? $this->faker->sentence(),
            'status' => TranslationStatus::Translated,
        ]);
    }

    /** Mark the translation as reviewed with an optional value. */
    public function reviewed(?string $value = null): static
    {
        return $this->state([
            'value' => $value ?? $this->faker->sentence(),
            'status' => TranslationStatus::Reviewed,
        ]);
    }

    /** Set an explicit value without changing the status. */
    public function withValue(string $value): static
    {
        return $this->state(['value' => $value]);
    }

    /** Create a source-language translation with a value. */
    public function forSource(string $value): static
    {
        return $this->state([
            'value' => $value,
            'status' => TranslationStatus::Translated,
        ]);
    }
}
