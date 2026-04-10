<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\Scanner;

use Syriable\Translator\DTOs\ScannedFile;

/**
 * Extracts translation key strings from PHP, Blade, JavaScript, TypeScript,
 * and Vue source files by matching all standard Laravel translation call forms.
 *
 * Supported call forms:
 *   PHP / Blade:
 *     __('key'), __("key")
 *     trans('key'), trans("key")
 *     trans_choice('key', $n), trans_choice("key", $n)
 *     @lang('key'), @lang("key")
 *     Lang::get('key'), Lang::get("key")
 *     Lang::choice('key', $n), Lang::has('key'), Lang::getFromJson('key')
 *
 *   JavaScript / TypeScript / Vue:
 *     __('key'), __("key"), __(`key`)
 *     $t('key'), $t("key")
 *     i18n.t('key'), i18n.t("key")
 *
 * Keys containing PHP variable interpolation (e.g. __("auth.$action")) or
 * runtime string concatenation cannot be statically analysed and are silently
 * skipped. This is correct behaviour — only literal keys can be tracked.
 */
final class TranslationUsageExtractor
{
    /**
     * Regex patterns for PHP and Blade translation call forms.
     *
     * Each pattern captures the key string in capture group 1.
     * Key contents are matched with [^'"\\]+ which excludes quote characters
     * and backslashes — translation keys never legitimately contain these.
     *
     * @var string[]
     */
    private const array PHP_PATTERNS = [
        // __('key') — primary Laravel helper (single-quoted)
        '/\b__\s*\(\s*\'([^\'\\\\]+)\'/',
        // __("key") — primary Laravel helper (double-quoted)
        '/\b__\s*\(\s*"([^"\\\\]+)"/',
        // trans('key'), trans("key")
        '/\btrans\s*\(\s*\'([^\'\\\\]+)\'/',
        '/\btrans\s*\(\s*"([^"\\\\]+)"/',
        // trans_choice('key', $n)
        '/\btrans_choice\s*\(\s*\'([^\'\\\\]+)\'/',
        '/\btrans_choice\s*\(\s*"([^"\\\\]+)"/',
        // @lang('key') — Blade directive
        '/@lang\s*\(\s*\'([^\'\\\\]+)\'/',
        '/@lang\s*\(\s*"([^"\\\\]+)"/',
        // Lang::get('key'), Lang::choice('key'), Lang::has('key'), Lang::getFromJson('key')
        '/Lang\s*::\s*(?:get|choice|has|getFromJson)\s*\(\s*\'([^\'\\\\]+)\'/',
        '/Lang\s*::\s*(?:get|choice|has|getFromJson)\s*\(\s*"([^"\\\\]+)"/',
    ];

    /**
     * Regex patterns for JavaScript, TypeScript, and Vue source files.
     *
     * @var string[]
     */
    private const array JS_PATTERNS = [
        // __('key'), __("key"), __(`key`) — common helper
        '/\b__\s*\(\s*\'([^\'\\\\]+)\'/',
        '/\b__\s*\(\s*"([^"\\\\]+)"/',
        '/\b__\s*\(\s*`([^`\\\\]+)`/',
        // $t('key'), $t("key") — Vue I18n / typical Inertia pattern
        '/\$t\s*\(\s*\'([^\'\\\\]+)\'/',
        '/\$t\s*\(\s*"([^"\\\\]+)"/',
        // i18n.t('key'), i18n.t("key") — direct i18n instance call
        '/\bi18n\s*\.\s*t\s*\(\s*\'([^\'\\\\]+)\'/',
        '/\bi18n\s*\.\s*t\s*\(\s*"([^"\\\\]+)"/',
    ];

    /**
     * File extensions handled by PHP/Blade patterns.
     *
     * @var string[]
     */
    private const array PHP_EXTENSIONS = ['php', 'blade.php'];

    /**
     * File extensions handled by JS patterns.
     *
     * @var string[]
     */
    private const array JS_EXTENSIONS = ['js', 'ts', 'vue'];

    /**
     * Extract all translation keys referenced in the given source file.
     *
     * Returns an empty array when the file cannot be read, has an unsupported
     * extension, or contains no recognisable translation calls.
     *
     * @param  ScannedFile  $file  The source file to analyse.
     * @return string[] Unique translation key strings found in the file.
     */
    public function extractFromFile(ScannedFile $file): array
    {
        $content = @file_get_contents($file->absolutePath);

        if ($content === false || $content === '') {
            return [];
        }

        return $this->extractFromContent($content, $this->resolveExtension($file->absolutePath));
    }

    /**
     * Extract all translation keys from raw file content.
     *
     * Applies the appropriate pattern set based on the file extension.
     * Returns a deduplicated, sorted array of key strings.
     *
     * @param  string  $content    Raw file content.
     * @param  string  $extension  Lowercase file extension without leading dot.
     * @return string[] Unique translation keys extracted.
     */
    public function extractFromContent(string $content, string $extension): array
    {
        $patterns = $this->resolvePatterns($extension);

        if (empty($patterns)) {
            return [];
        }

        $keys = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches) > 0) {
                foreach ($matches[1] as $key) {
                    $trimmed = trim($key);

                    if ($this->isValidKey($trimmed)) {
                        $keys[] = $trimmed;
                    }
                }
            }
        }

        $unique = array_unique($keys);
        sort($unique);

        return array_values($unique);
    }

    /**
     * Resolve the set of regex patterns appropriate for the given extension.
     *
     * @return string[]
     */
    private function resolvePatterns(string $extension): array
    {
        if (in_array($extension, self::PHP_EXTENSIONS, strict: true)) {
            return self::PHP_PATTERNS;
        }

        if (in_array($extension, self::JS_EXTENSIONS, strict: true)) {
            return self::JS_PATTERNS;
        }

        return [];
    }

    /**
     * Determine whether a candidate key string is a valid, statically-known key.
     *
     * Rejects keys that:
     *  - Are empty or whitespace-only.
     *  - Contain PHP variable sigils (e.g. "auth.$action" — dynamic key).
     *  - Contain template literal interpolation (e.g. `Hello ${name}`).
     *  - Are suspiciously long (> 255 chars — not a real translation key).
     *
     * @param  string  $key  The candidate key string to validate.
     */
    private function isValidKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        // Reject keys containing PHP variable interpolation.
        if (str_contains($key, '$')) {
            return false;
        }

        // Reject keys containing JS template literal interpolation.
        if (str_contains($key, '${')) {
            return false;
        }

        // Reject unrealistically long strings.
        if (mb_strlen($key) > 255) {
            return false;
        }

        return true;
    }

    /**
     * Resolve a normalised, lowercase file extension from an absolute path.
     *
     * Handles compound extensions such as `.blade.php` by returning the longest
     * matching extension rather than just the final component.
     *
     * @param  string  $absolutePath  Absolute path to the file.
     */
    private function resolveExtension(string $absolutePath): string
    {
        $normalised = str_replace('\\', '/', $absolutePath);

        // Check for compound Blade extension first.
        if (str_ends_with($normalised, '.blade.php')) {
            return 'blade.php';
        }

        $ext = pathinfo($absolutePath, PATHINFO_EXTENSION);

        return strtolower($ext);
    }
}