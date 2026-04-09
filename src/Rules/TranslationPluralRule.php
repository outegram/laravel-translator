<?php

declare(strict_types=1);

namespace Syriable\Translator\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

/**
 * Validates that a plural translation value contains the same number of
 * pipe-delimited variants as the source language translation.
 *
 * Laravel's pluralisation syntax uses pipe characters to separate variants:
 *   `'one apple|many apples'`
 *   `'{1} apple|[2,*] apples'`
 *
 * Passes silently when:
 *  - The key is not marked as plural.
 *  - The submitted value is blank.
 *  - No source language translation exists yet (deferred validation).
 *  - The segment count matches the source exactly.
 *
 * Usage:
 * ```php
 * 'value' => ['nullable', 'string', new TranslationPluralRule($translationKey)],
 * ```
 */
final readonly class TranslationPluralRule implements ValidationRule
{
    /**
     * The pipe character used as Laravel's plural variant delimiter.
     */
    private const string PLURAL_DELIMITER = '|';

    /**
     * @param  TranslationKey  $translationKey  The key whose plural structure the value must match.
     */
    public function __construct(
        private TranslationKey $translationKey,
    ) {}

    /**
     * Validate that the translation value has the correct number of plural variants.
     *
     * @param  string  $attribute  The name of the attribute being validated.
     * @param  mixed  $value  The submitted translation value.
     * @param  Closure  $fail  Callback to invoke with a failure message.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->translationKey->is_plural || blank($value)) {
            return;
        }

        $sourceValue = $this->resolveSourceTranslationValue();

        if ($sourceValue === null) {
            return;
        }

        $expectedCount = $this->countVariants($sourceValue);
        $actualCount = $this->countVariants((string) $value);

        if ($actualCount !== $expectedCount) {
            $fail($this->buildFailureMessage($expectedCount, $actualCount));
        }
    }

    /**
     * Retrieve the source language translation value for the current key.
     *
     * Returns null when no source translation has been persisted yet,
     * allowing the rule to pass rather than block progress on a key whose
     * source is not yet fully imported.
     */
    private function resolveSourceTranslationValue(): ?string
    {
        return Translation::query()
            ->where('translation_key_id', $this->translationKey->id)
            ->whereHas('language', static fn ($query) => $query->where('is_source', true))
            ->value('value');
    }

    /**
     * Count the number of plural variants in a translation string.
     *
     * A string with no pipe character yields a count of 1.
     *
     * @param  string  $value  A raw plural translation string.
     */
    private function countVariants(string $value): int
    {
        return count(explode(self::PLURAL_DELIMITER, $value));
    }

    /**
     * Build a localised validation failure message describing the variant mismatch.
     *
     * @param  int  $expected  The number of variants required (from the source).
     * @param  int  $actual  The number of variants found in the submitted value.
     */
    private function buildFailureMessage(int $expected, int $actual): string
    {
        return trans('translator::validation.plural_variant_mismatch', [
            'expected' => $expected,
            'actual' => $actual,
        ]);
    }
}
