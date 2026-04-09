<?php

declare(strict_types=1);

namespace Syriable\Translator\AI\Prompts;

use Syriable\Translator\DTOs\AI\TranslationRequest;

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
 */
final class TranslationPromptBuilder
{
    /**
     * Build the system-role prompt containing persistent translation rules.
     *
     * The system prompt is sent once per session and governs all translation
     * behaviour. It instructs the model on placeholder preservation, plural
     * handling, consistency requirements, and output format.
     *
     * @param  TranslationRequest  $request  The request for which to build the system prompt.
     */
    public function buildSystemPrompt(TranslationRequest $request): string
    {
        $existingTranslations = $this->renderExistingTranslationContext();

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

            <rule id="plurals">
                Laravel uses pipe syntax for plurals: "one item|many items" or "{1} item|[2,*] items".
                When translating plural strings:
                - Preserve the pipe (|) delimiter
                - Maintain the same number of pipe-separated variants as the source
                - Apply grammatically correct plural forms for {$request->targetLanguage}
                - Never add or remove pipe variants
            </rule>

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

        {$existingTranslations}
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

    /**
     * Render existing translation context for the system prompt when a
     * translation memory is available.
     *
     * Returns an empty string when no context is configured, keeping the
     * prompt lean for first-time translations.
     */
    private function renderExistingTranslationContext(): string
    {
        // Translation memory integration is a future extension point.
        // When implemented, this method will query the TranslationMemory
        // service and render relevant prior translations as context.
        return '';
    }
}
