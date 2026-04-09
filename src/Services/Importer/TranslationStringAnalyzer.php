<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\Importer;

/**
 * Analyses translation string content to extract metadata about its structure.
 *
 * Detects interpolation parameters, HTML content, and plural forms — the three
 * structural characteristics that affect how a translation string is stored,
 * rendered, and edited.
 *
 * Supports two Laravel parameter syntaxes:
 *  - Colon-prefixed:  `:attribute`, `:name`
 *  - Brace-wrapped:   `{attribute}`, `{count}`
 *
 * Results from this class are stored on TranslationKey records at import time
 * and are later used by TranslationParametersRule and TranslationPluralRule
 * for validation.
 */
final class TranslationStringAnalyzer
{
    /**
     * Regex pattern for colon-prefixed Laravel parameters (e.g. `:name`, `:attribute`).
     *
     * The negative lookbehind `(?<!\w)` prevents matching colons that follow a
     * word character, avoiding false positives in URLs (e.g. `https://`).
     */
    private const string PATTERN_COLON_PARAMETER = '/(?<!\w):([a-zA-Z_][a-zA-Z0-9_]*)/';

    /**
     * Regex pattern for brace-wrapped parameters (e.g. `{name}`, `{count}`).
     */
    private const string PATTERN_BRACE_PARAMETER = '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/';

    /**
     * Regex pattern for detecting opening HTML tags (e.g. `<br>`, `<strong>`, `<a href="...">`).
     *
     * Intentionally matches only opening and self-closing tags.
     * Closing tags alone (e.g. `</strong>`) are not considered HTML indicators.
     */
    private const string PATTERN_HTML_TAG = '/<[a-zA-Z][a-zA-Z0-9]*(\s[^>]*)?\/?>/s';

    /**
     * Extract all interpolation parameter tokens from a translation string.
     *
     * Returns the full token as it appears in the string, preserving the syntax
     * used (colon-prefixed or brace-wrapped), so callers can render parameters
     * accurately without re-inferring the format.
     *
     * Example:
     *   Input:  'Hello :name, you have {count} messages.'
     *   Output: [':name', '{count}']
     *
     * @param  string  $text  A raw translation string value.
     * @return string[] Unique parameter tokens in order of first appearance.
     */
    public function extractParameters(string $text): array
    {
        preg_match_all(self::PATTERN_COLON_PARAMETER, $text, $colonMatches);
        preg_match_all(self::PATTERN_BRACE_PARAMETER, $text, $braceMatches);

        $tokens = array_merge($colonMatches[0], $braceMatches[0]);

        return array_values(array_unique($tokens));
    }

    /**
     * Determine whether a translation string contains inline HTML markup.
     *
     * Used to flag strings that may require special rendering or sanitization
     * when displayed in a translation editor UI.
     *
     * @param  string  $text  A raw translation string value.
     */
    public function containsHtml(string $text): bool
    {
        return (bool) preg_match(self::PATTERN_HTML_TAG, $text);
    }

    /**
     * Determine whether a translation string uses Laravel's plural pipe syntax.
     *
     * Laravel plural strings use an unspaced pipe as the separator:
     *   `'one apple|many apples'`
     *   `'{1} apple|[2,*] apples'`
     *
     * A pipe surrounded by spaces (` | `) is treated as intentional literal
     * text rather than a plural delimiter, and is therefore excluded.
     *
     * @param  string  $text  A raw translation string value.
     */
    public function isPlural(string $text): bool
    {
        return str_contains($text, '|')
            && ! str_contains($text, ' | ');
    }
}
