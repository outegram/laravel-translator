<?php

declare(strict_types=1);

namespace Syriable\Translator\Support;

/**
 * Provides the number of plural forms required by each locale, derived from
 * Unicode CLDR (Common Locale Data Repository) plural rules.
 *
 * Used by TranslationPromptBuilder to inject the correct plural form count into
 * the AI system prompt, preventing the most common LLM plural failure: producing
 * two-variant output for a language that requires three, four, five, or six forms.
 *
 * Laravel's pipe plural syntax (`one|few|many|other`) requires exactly as many
 * pipe-separated variants as the target language's CLDR plural form count.
 *
 * References:
 *  - https://www.unicode.org/cldr/charts/latest/supplemental/language_plural_rules.html
 *  - https://cldr.unicode.org/index/cldr-spec/plural-rules
 */
final class PluralFormProvider
{
    /**
     * Locales with exactly one plural form (no distinction between quantities).
     * All quantities use the same string. No pipe separator needed.
     *
     * @var string[]
     */
    private const array ONE_FORM = [
        'az', 'bm', 'bo', 'dz', 'fa', 'id', 'ig', 'ii', 'in', 'ja',
        'jbo', 'jv', 'jw', 'ka', 'kde', 'kea', 'km', 'ko', 'lo', 'ms',
        'my', 'nqo', 'root', 'sah', 'ses', 'sg', 'th', 'to', 'tr',
        'vi', 'wo', 'yo', 'zh', 'zh-Hant',
    ];

    /**
     * Locales with exactly two plural forms (one | other).
     * The overwhelming majority of European and many other languages.
     *
     * @var string[]
     */
    private const array TWO_FORMS = [
        'af', 'ak', 'am', 'an', 'ast', 'az', 'bg', 'bn', 'br', 'ca',
        'da', 'de', 'el', 'en', 'en-GB', 'en-US', 'eo', 'es', 'et',
        'eu', 'fi', 'fil', 'fr', 'fy', 'gl', 'gu', 'ha', 'hi', 'hy',
        'is', 'it', 'ja', 'km', 'kn', 'ku', 'lb', 'mk', 'ml', 'mn',
        'mr', 'nb', 'ne', 'nl', 'or', 'pa', 'pt', 'pt-BR', 'pt-PT',
        'rm', 'rof', 'si', 'sq', 'sv', 'sw', 'ta', 'te', 'tk', 'ur',
        'uz', 'yi', 'zu',
    ];

    /**
     * Locales with exactly three plural forms.
     *
     * @var string[]
     */
    private const array THREE_FORMS = [
        'be', 'bs', 'hr', 'lt', 'lv', 'ro', 'ru', 'sh', 'sr', 'uk',
    ];

    /**
     * Locales with exactly four plural forms.
     *
     * @var string[]
     */
    private const array FOUR_FORMS = [
        'cs', 'gd', 'he', 'pl', 'sk', 'sl',
    ];

    /**
     * Locales with exactly five plural forms.
     *
     * @var string[]
     */
    private const array FIVE_FORMS = [
        'ga',
    ];

    /**
     * Locales with exactly six plural forms.
     * Arabic and Welsh have the most complex plural systems in common use.
     *
     * @var string[]
     */
    private const array SIX_FORMS = [
        'ar', 'cy',
    ];

    /**
     * Form names by count, used to generate descriptive prompt instructions.
     * Names follow the Unicode CLDR standard category labels.
     *
     * @var array<int, string[]>
     */
    private const array FORM_NAMES = [
        1 => ['other'],
        2 => ['one', 'other'],
        3 => ['one', 'few', 'other'],
        4 => ['one', 'few', 'many', 'other'],
        5 => ['one', 'two', 'few', 'many', 'other'],
        6 => ['zero', 'one', 'two', 'few', 'many', 'other'],
    ];

    /**
     * Return the number of plural forms required by the given locale.
     *
     * Returns 2 as the safe default when the locale is not explicitly catalogued —
     * the vast majority of uncatalogued locales use a two-form system.
     *
     * @param  string  $locale  BCP 47 locale code (e.g. 'ar', 'ru', 'en').
     */
    public static function formCount(string $locale): int
    {
        if (in_array($locale, self::ONE_FORM, strict: true)) {
            return 1;
        }

        if (in_array($locale, self::TWO_FORMS, strict: true)) {
            return 2;
        }

        if (in_array($locale, self::THREE_FORMS, strict: true)) {
            return 3;
        }

        if (in_array($locale, self::FOUR_FORMS, strict: true)) {
            return 4;
        }

        if (in_array($locale, self::FIVE_FORMS, strict: true)) {
            return 5;
        }

        if (in_array($locale, self::SIX_FORMS, strict: true)) {
            return 6;
        }

        // Safe default for any unrecognised locale.
        return 2;
    }

    /**
     * Return the CLDR standard category names for the given locale's plural forms.
     *
     * Example: 'ar' → ['zero', 'one', 'two', 'few', 'many', 'other']
     *          'ru' → ['one', 'few', 'other']
     *          'en' → ['one', 'other']
     *
     * @param  string  $locale  BCP 47 locale code.
     * @return string[] Ordered list of CLDR category labels.
     */
    public static function formNames(string $locale): array
    {
        $count = self::formCount($locale);

        return self::FORM_NAMES[$count] ?? self::FORM_NAMES[2];
    }

    /**
     * Determine whether the given locale uses exactly one plural form (no
     * distinction between singular and plural).
     *
     * When true, pipe syntax should not be used at all for this locale.
     *
     * @param  string  $locale  BCP 47 locale code.
     */
    public static function isSingular(string $locale): bool
    {
        return self::formCount($locale) === 1;
    }

    /**
     * Build a concise, human-readable description of the plural form requirement
     * for injection into an AI prompt.
     *
     * Example outputs:
     *   'ar' → "Arabic requires 6 plural forms: zero | one | two | few | many | other"
     *   'ru' → "Russian requires 3 plural forms: one | few | other"
     *   'en' → "English requires 2 plural forms: one | other"
     *   'zh' → "Chinese requires 1 plural form. Do not use pipe separators."
     *
     * @param  string  $locale  BCP 47 locale code.
     * @param  string  $languageName  Human-readable language name for the description.
     */
    public static function describe(string $locale, string $languageName): string
    {
        $count = self::formCount($locale);

        if ($count === 1) {
            return "{$languageName} requires 1 plural form. Do not use pipe separators — every quantity uses the same string.";
        }

        $names = implode(' | ', self::formNames($locale));

        return "{$languageName} requires {$count} plural forms: {$names}";
    }
}
