<?php

declare(strict_types=1);

namespace Syriable\Translator\AI\Prompts;

use Illuminate\Support\Facades\Cache;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Support\PluralFormProvider;

/**
 * Builds structured prompts for AI translation providers.
 *
 * Memory leak fix: Previous versions stored `$languageNameCache` and
 * `$translationMemoryCache` as instance-level arrays on a singleton service.
 * In long-running processes (Octane, queue workers), these arrays grew without
 * bound and never reflected changes made to Language records or new Reviewed
 * translations added during the worker's lifetime.
 *
 * The fix replaces both arrays with `Cache::remember()` calls backed by the
 * application cache store (Redis/file/database). Benefits:
 *
 * - Bounded memory: no per-process growth, TTL controls staleness.
 * - Cross-process consistency: all workers see the same cached values.
 * - Automatic invalidation: the TranslationObserver calls `Cache::forget()` on
 *   the memory key whenever a Translation is updated to Reviewed status, so the
 *   next batch automatically picks up the new approved translation.
 *
 * Cache key constants are public so that the TranslationObserver can build
 * the exact same keys without duplicating the format string.
 */
final class TranslationPromptBuilder
{
    public const string MEMORY_CACHE_PREFIX = 'translator:prompt_builder:memory';

    public const string LANG_NAME_CACHE_PREFIX = 'translator:prompt_builder:lang_name';

    /**
     * Build the system-role prompt containing persistent translation rules.
     *
     * The system prompt is sent once per session and governs all translation
     * behaviour. It instructs the model on placeholder preservation, plural
     * handling, consistency requirements, and output format.
     *
     * When translation memory is available for the target language, a
     * `<translation_memory>` section is appended to reinforce terminology
     * consistency with already-reviewed translations.
     */
    public function buildSystemPrompt(TranslationRequest $request): string
    {
        $pluralRule = $this->buildPluralRule($request);
        $translationMemory = $this->renderTranslationMemory($request);

        return <<<PROMPT
        You are a professional software localisation engineer specialising in Laravel PHP applications.
        Your task is to translate UI strings from {$request->sourceLanguage} to {$request->targetLanguage}.

        <rules>
            <rule id="placeholders">
                Preserve ALL placeholder tokens exactly as they appear. Never translate or modify:
                - Colon-prefixed parameters: :name, :count, :attribute
                - Brace-wrapped parameters: {name}, {count}
                - HTML tags: <strong>, <a href="">, <br/>
                - URLs and email addresses
            </rule>

            {$pluralRule}

            <rule id="consistency">
                Maintain terminology consistency:
                - Use the same word for the same concept throughout all translations
                - Reuse translations from the existing context when provided
                - Keep UI element labels (buttons, errors, tooltips) uniform in tone
                - Match the formality level of the source text
            </rule>

            <rule id="formatting">
                Preserve all formatting exactly:
                - Leading/trailing whitespace
                - Punctuation style (period at end, exclamation marks, etc.)
                - Capitalisation patterns (sentence case vs title case)
                - Line breaks within values
            </rule>

            <rule id="output_format">
                Return ONLY a valid JSON object. No explanation, no markdown, no code fences.
                The JSON must contain exactly the translated keys provided in the request.
                Keys must be identical to the input keys. Values must be the translated strings.
                Example: {"auth.failed": "Los datos ingresados no coinciden."}
            </rule>
        </rules>

        {$translationMemory}
        PROMPT;
    }

    public function buildUserMessage(TranslationRequest $request): string
    {
        $keysJson = json_encode($request->keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $context = $request->context ? "\n        <context>{$request->context}</context>" : '';
        $pluralNote = $request->preservePlurals
            ? '<plural_handling>enabled — preserve all pipe delimiters and variant counts</plural_handling>'
            : '<plural_handling>disabled — treat pipe characters as literal text</plural_handling>';

        return <<<MESSAGE
        <translation_request>
            <source_language>{$request->sourceLanguage}</source_language>
            <target_language>{$request->targetLanguage}</target_language>
            <group>{$request->qualifiedGroupName()}</group>
            {$pluralNote}{$context}
        </translation_request>

        <translation_keys>
        {$keysJson}
        </translation_keys>

        Translate all values from {$request->sourceLanguage} to {$request->targetLanguage}.
        Return only the JSON object with the same keys and translated values.
        MESSAGE;
    }

    public function measurePromptLength(TranslationRequest $request): int
    {
        return mb_strlen($this->buildSystemPrompt($request))
            + mb_strlen($this->buildUserMessage($request));
    }

    // -------------------------------------------------------------------------
    // Plural form rule
    // -------------------------------------------------------------------------

    private function buildPluralRule(TranslationRequest $request): string
    {
        $formCount = PluralFormProvider::formCount($request->targetLanguage);
        $language = $this->resolveLanguageName($request->targetLanguage);
        $formDescription = PluralFormProvider::describe($request->targetLanguage, $language);

        if ($formCount === 1) {
            return <<<RULE
            <rule id="plurals">
                {$formDescription}
                Source strings may contain pipe syntax — when translating into {$request->targetLanguage},
                collapse all pipe-separated variants into a single string with no pipes.
            </rule>
            RULE;
        }

        $formNames = implode(' | ', PluralFormProvider::formNames($request->targetLanguage));
        $examplePipes = implode('|', array_fill(0, $formCount, '...'));

        return <<<RULE
        <rule id="plurals">
            Laravel uses pipe syntax for plurals: "one item|many items" or "{1} item|[2,*] items".
            {$formDescription}
            When translating plural strings:
            - The translated value MUST contain exactly {$formCount} pipe-separated variants: {$formNames}
            - Format: {$examplePipes}
            - Never produce fewer or more than {$formCount} variants for {$request->targetLanguage}
            - Apply grammatically correct plural forms for each category
            - Never add or remove pipe variants relative to this requirement
        </rule>
        RULE;
    }

    // -------------------------------------------------------------------------
    // Language name resolution — Cache-backed to prevent DB hit per key
    // -------------------------------------------------------------------------

    /**
     * Resolve a human-readable language name from its BCP 47 code.
     *
     * Fix: replaced instance-level `$languageNameCache` array (unbounded in
     * long-running processes) with `Cache::remember()`. The cache key uses
     * a dedicated prefix so the TranslationObserver can selectively clear it.
     *
     * TTL: 1 hour. Language names change extremely rarely; this TTL is safe.
     */
    private function resolveLanguageName(string $localeCode): string
    {
        $cacheKey = self::LANG_NAME_CACHE_PREFIX.":{$localeCode}";
        $ttl = (int) config('translator.ai.translation_memory.lang_name_cache_ttl', 3600);

        /** @var string */
        return Cache::remember(
            $cacheKey,
            $ttl,
            static fn (): string => Language::query()->where('code', $localeCode)->value('name') ?? $localeCode,
        );
    }

    // -------------------------------------------------------------------------
    // Translation memory — Cache-backed with observer invalidation
    // -------------------------------------------------------------------------

    /**
     * Build the `<translation_memory>` section for the system prompt.
     *
     * Fix: replaced instance-level `$translationMemoryCache` array with
     * `Cache::remember()`. The key is structured as:
     *
     *   `translator:prompt_builder:memory:{locale}`
     *
     * The TranslationObserver watches for Translation saves and calls
     * `Cache::forget()` on this key when a translation is updated to Reviewed
     * status, ensuring the next batch automatically includes the new approval.
     *
     * TTL: configurable via `translator.ai.translation_memory.cache_ttl`
     * (default 3600s). Set to 0 to disable memory caching (not recommended
     * in high-volume environments).
     */
    private function renderTranslationMemory(TranslationRequest $request): string
    {
        if (! config('translator.ai.translation_memory.enabled', true)) {
            return '';
        }

        $cacheKey = self::MEMORY_CACHE_PREFIX.":{$request->targetLanguage}";
        $ttl = (int) config('translator.ai.translation_memory.cache_ttl', 3600);

        if ($ttl === 0) {
            return $this->buildTranslationMemoryContent($request->targetLanguage);
        }

        /** @var string */
        return Cache::remember(
            $cacheKey,
            $ttl,
            fn (): string => $this->buildTranslationMemoryContent($request->targetLanguage),
        );
    }

    /**
     * Execute the database queries and render the `<translation_memory>` block.
     */
    private function buildTranslationMemoryContent(string $targetLanguage): string
    {
        $limit = max(1, (int) config('translator.ai.translation_memory.limit', 20));

        /** @var Language|null $language */
        $language = Language::query()
            ->where('code', $targetLanguage)
            ->first();

        if ($language === null) {
            return '';
        }

        $examples = Translation::query()
            ->reviewed()
            ->where('language_id', $language->id)
            ->whereNotNull('value')
            ->whereHas('translationKey.group', static fn ($q) => $q->whereNull('namespace'))
            ->with(['translationKey.group'])
            ->limit($limit)
            ->get()
            ->filter(static fn (Translation $t): bool => $t->translationKey !== null && filled($t->value))
            ->mapWithKeys(static function (Translation $t): array {
                $key = $t->translationKey;
                $group = $key->group;

                $qualifiedKey = $group->name === Group::JSON_GROUP_NAME
                    ? $key->key
                    : $group->name.'.'.$key->key;

                return [$qualifiedKey => $t->value];
            });

        if ($examples->isEmpty()) {
            return '';
        }

        $json = json_encode($examples->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<MEMORY

        <translation_memory>
            The following strings have been reviewed and approved for {$targetLanguage}.
            When you encounter these keys in the current request, use these exact translations.
            For other keys, use these as a reference for consistent terminology and tone.
            {$json}
        </translation_memory>
        MEMORY;
    }
}
