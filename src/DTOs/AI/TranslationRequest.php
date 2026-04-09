<?php

declare(strict_types=1);

namespace Syriable\Translator\DTOs\AI;

/**
 * Immutable value object encapsulating everything an AI provider needs to
 * translate a batch of keys from one language to another.
 *
 * A single request maps to one source file / group and one target language.
 * For large files, multiple requests should be created and queued individually
 * to stay within provider token limits.
 *
 * Typical usage:
 * ```php
 * $request = new TranslationRequest(
 *     sourceLanguage: 'en',
 *     targetLanguage: 'ar',
 *     keys: ['auth.failed' => 'These credentials do not match.'],
 *     groupName: 'auth',
 *     namespace: null,
 * );
 * ```
 */
final readonly class TranslationRequest
{
    /**
     * @param  string  $sourceLanguage  BCP 47 code of the reference language (e.g. 'en').
     * @param  string  $targetLanguage  BCP 47 code of the language to translate into (e.g. 'ar').
     * @param  array<string, string>  $keys  Map of translation key => source string value to translate.
     * @param  string  $groupName  Name of the translation group / file (e.g. 'auth', '_json').
     * @param  string|null  $namespace  Vendor namespace, or null for application files.
     * @param  bool  $preservePlurals  Whether to detect and preserve Laravel plural pipe syntax.
     * @param  string|null  $context  Optional domain context hint to guide translation quality.
     */
    public function __construct(
        public string $sourceLanguage,
        public string $targetLanguage,
        public array $keys,
        public string $groupName,
        public ?string $namespace = null,
        public bool $preservePlurals = true,
        public ?string $context = null,
    ) {}

    /**
     * Return the total number of keys in this request.
     */
    public function keyCount(): int
    {
        return count($this->keys);
    }

    /**
     * Return the combined character count of all source string values.
     *
     * Used by cost estimators to calculate approximate input token counts.
     */
    public function totalSourceCharacters(): int
    {
        return (int) array_sum(array_map(mb_strlen(...), $this->keys));
    }

    /**
     * Return a qualified group identifier for use in prompts and logs.
     *
     * Format: `namespace::group` for vendor files, `group` for application files.
     */
    public function qualifiedGroupName(): string
    {
        return $this->namespace !== null
            ? "{$this->namespace}::{$this->groupName}"
            : $this->groupName;
    }
}
