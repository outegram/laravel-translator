<?php

declare(strict_types=1);

namespace Syriable\Translator\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Syriable\Translator\Models\TranslationKey;

/**
 * Validates that a translated string value preserves all interpolation
 * parameters defined on its source TranslationKey.
 *
 * A translation is considered invalid when one or more parameter tokens
 * (e.g. `:name`, `{count}`) present in the source key are absent from
 * the submitted value. Empty values are considered valid — presence of
 * a value is a separate concern enforced by a required rule.
 *
 * Usage:
 * ```php
 * 'value' => ['nullable', 'string', new TranslationParametersRule($translationKey)],
 * ```
 *
 * @see TranslationKey::parameterNames()
 */
final readonly class TranslationParametersRule implements ValidationRule
{
    /**
     * @param  TranslationKey  $translationKey  The key whose parameters the value must preserve.
     */
    public function __construct(
        private TranslationKey $translationKey,
    ) {}

    /**
     * Validate that the translation value contains all required parameter tokens.
     *
     * Passes silently when:
     *  - The value is empty (blank string or null-ish).
     *  - The key defines no parameters.
     *  - All parameters are present in the submitted value.
     *
     * @param  string  $attribute  The name of the attribute being validated.
     * @param  mixed  $value  The submitted translation value.
     * @param  Closure  $fail  Callback to invoke with a failure message.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value) || ! $this->translationKey->hasParameters()) {
            return;
        }

        $missingParameters = $this->resolveMissingParameters((string) $value);

        if ($missingParameters !== []) {
            $fail($this->buildFailureMessage($missingParameters));
        }
    }

    /**
     * Determine which parameter tokens from the key are absent in the given value.
     *
     * Can be called statically for use in non-validation contexts, such as
     * displaying a diff of missing parameters in a translation editor UI.
     *
     * @param  TranslationKey  $translationKey  The key to read expected parameters from.
     * @param  string  $value  The translation string to check.
     * @return string[] Parameter tokens missing from the value (e.g. [':name', '{count}']).
     */
    public static function missingParametersFor(TranslationKey $translationKey, string $value): array
    {
        return array_values(
            array_filter(
                $translationKey->parameterNames(),
                static fn (string $parameter): bool => ! str_contains($value, $parameter),
            ),
        );
    }

    /**
     * Resolve the parameter tokens missing from the submitted value.
     *
     * @param  string  $value  The submitted translation string.
     * @return string[] Missing parameter tokens.
     */
    private function resolveMissingParameters(string $value): array
    {
        return self::missingParametersFor($this->translationKey, $value);
    }

    /**
     * Build a localised validation failure message listing all missing parameters.
     *
     * @param  string[]  $missingParameters
     */
    private function buildFailureMessage(array $missingParameters): string
    {
        return trans('translator::validation.missing_parameters', [
            'parameters' => implode(', ', $missingParameters),
        ]);
    }
}
