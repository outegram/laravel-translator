<?php

declare(strict_types=1);

namespace Syriable\Translator\AI\Prompts;

use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Support\PluralFormProvider;

/**
 * Builds structured prompts for AI translation providers.
 *
 * The prompt format uses XML-tagged sections to give the AI model clear,
 * unambiguous structure. XML is preferred over JSON or plain text because:
 *  - It tolerates special characters (quotes, colons, pipes) without escaping.
 *  - It allows inline rules to be close to the data they govern.
 *  - Claude and other modern LLMs are specifically trained to follow XML-tagged instructions.
 *
 * The prompt is split into a system message (persistent rules) and a user
 * message (request-specific content), matching the message-role API pattern
 * used by Claude, GPT-4, and Gemini.
 *
 * ### Translation Memory
 *
 * When `translator.ai.translation_memory.enabled` is true (default), the system
 * prompt includes a `<translation_memory>` block populated with up to
 * `translator.ai.translation_memory.limit` previously reviewed translations for
 * the target language. This enforces terminology consistency across batches and
 * across AI translation runs.
 *
 * Memory is sourced exclusively from `Reviewed` status translations — values
 * that have been explicitly approved — to prevent propagating unreviewed errors.
 *
 * ### Plural Form Awareness
 *
 * The plural rule in the system prompt is enriched with the exact number of
 * pipe-delimited forms required by the target language, derived from Unicode
 * CLDR data via PluralFormProvider. This prevents the common LLM failure of
 * producing two-variant output for a language that requires three, four, or six.
 */
final class TranslationPromptBuilder
{
    /**
     * Per-instance cache for resolved language names, keyed by locale code.
     *
     * Populated lazily by resolveLanguageName(). Since the driver singletons
     * hold a single TranslationPromptBuilder instance across the entire request
     * lifecycle, this provides the same "one DB hit per locale" guarantee as a
     * static cache while preserving test isolation between test cases.
     *
     * @var array<string, string>
     */
    private array $languageNameCache = [];

    /**
     * Per-instance cache for rendered translation memory blocks, keyed by
     * target locale code.
     *
     * @var array<string, string>
     */
    private array $translationMemoryCache = [];
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
     *
     * @param  TranslationRequest  $request  The request for which to build the system prompt.
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

    /**
     * Build the user-role message containing the specific translation request.
     *
     * The user message carries request-specific data: language pair, group name,
     * optional context, and the actual keys to translate.
     *
     * @param  TranslationRequest  $request  The translation request to render.
     */
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

    /**
     * Return the combined character length of both prompt parts.
     *
     * Used by TokenEstimator to include prompt overhead in token calculations.
     *
     * @param  TranslationRequest  $request  The request to measure.
     */
    public function measurePromptLength(TranslationRequest $request): int
    {
        return mb_strlen($this->buildSystemPrompt($request))
            + mb_strlen($this->buildUserMessage($request));
    }

    // -------------------------------------------------------------------------
    // Plural form rule
    // -------------------------------------------------------------------------

    /**
     * Build a plural rule XML block enriched with per-locale CLDR form counts.
     *
     * Injects the exact number of pipe-delimited variants the target language
     * requires, along with their standard CLDR category names. This prevents
     * the common AI failure of producing two-variant output for a language that
     * requires three, four, five, or six forms (e.g. Arabic, Russian, Polish).
     *
     * For single-form languages (e.g. Japanese, Chinese), the rule explicitly
     * instructs the model not to use pipe separators at all.
     */
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

    /**
     * Resolve a human-readable language name from its BCP 47 code.
     *
     * Uses a per-request static cache so that multiple calls within a single
     * batch (e.g. system prompt + plural rule) hit the database only once.
     *
     * Falls back to the code itself when no name is registered.
     */
    private function resolveLanguageName(string $localeCode): string
    {
        if (! array_key_exists($localeCode, $this->languageNameCache)) {
            $this->languageNameCache[$localeCode] =
                Language::query()->where('code', $localeCode)->value('name') ?? $localeCode;
        }

        return $this->languageNameCache[$localeCode];
    }

    // -------------------------------------------------------------------------
    // Translation memory
    // -------------------------------------------------------------------------

    /**
     * Build the `<translation_memory>` section for the system prompt.
     *
     * Queries up to `translator.ai.translation_memory.limit` reviewed
     * translations for the target language and formats them as a JSON reference
     * block. Returns an empty string when:
     *  - Translation memory is disabled in config.
     *  - No reviewed translations exist for the target language.
     *  - The target language is not found in the database.
     *
     * Keys are emitted in group-qualified form (e.g. "auth.failed") so the AI
     * model can correlate memory entries with keys in the current request.
     * JSON group (_json) keys are emitted as bare strings (no group prefix).
     *
     * Uses a per-request static cache keyed on target language to avoid
     * repeated identical queries when building system prompts within a batch.
     *
     * Source: `Reviewed` status only — never `Translated`, to avoid propagating
     * unreviewed AI output back into future AI prompts.
     */
    private function renderTranslationMemory(TranslationRequest $request): string
    {
        if (! config('translator.ai.translation_memory.enabled', true)) {
            return '';
        }

        $cacheKey = $request->targetLanguage;

        if (! array_key_exists($cacheKey, $this->translationMemoryCache)) {
            $this->translationMemoryCache[$cacheKey] =
                $this->buildTranslationMemoryContent($request->targetLanguage);
        }

        return $this->translationMemoryCache[$cacheKey];
    }

    /**
     * Execute the database queries and render the `<translation_memory>` block.
     *
     * Separated from renderTranslationMemory() so the static cache wraps only
     * the expensive work while keeping the query logic easy to test in isolation.
     */
    private function buildTranslationMemoryContent(string $targetLanguage): string
    {
        $limit = max(1, (int) config('translator.ai.translation_memory.limit', 20));

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
                $key   = $t->translationKey;
                $group = $key->group;

                // JSON group keys are bare strings (no group prefix).
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
