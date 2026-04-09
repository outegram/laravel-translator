<?php

declare(strict_types=1);

namespace Syriable\Translator\Support;

use Syriable\Translator\DTOs\LanguageDefinition;

/**
 * Static registry of all supported language definitions.
 *
 * Provides a typed, indexed catalogue of languages the translator package
 * recognises. Used by LanguageResolver when creating new Language records
 * for locale codes that have not yet been persisted.
 *
 * This class is intentionally a pure static data provider — it holds no state,
 * performs no I/O, and has no dependencies. The catalogue is built into a
 * code => LanguageDefinition index on first access and held for the lifetime
 * of the request.
 */
final class LanguageDataProvider
{
    /**
     * Indexed map of locale code => LanguageDefinition.
     *
     * Lazily built on first access.
     *
     * @var array<string, LanguageDefinition>|null
     */
    private static ?array $index = null;

    /**
     * Return all supported language definitions, indexed by locale code.
     *
     * @return array<string, LanguageDefinition>
     */
    public static function all(): array
    {
        return self::$index ??= self::buildIndex();
    }

    /**
     * Find a language definition by its locale code.
     *
     * Returns null when the code is not present in the catalogue, allowing
     * callers to fall back to the raw locale code as a display name.
     *
     * @param  string  $code  BCP 47 locale code (e.g. 'en', 'ar', 'pt-BR').
     */
    public static function findByCode(string $code): ?LanguageDefinition
    {
        return self::all()[$code] ?? null;
    }

    /**
     * Determine whether a locale code exists in the catalogue.
     *
     * @param  string  $code  BCP 47 locale code to check.
     */
    public static function supports(string $code): bool
    {
        return isset(self::all()[$code]);
    }

    /**
     * Build the locale-code-indexed map from the raw language catalogue.
     *
     * @return array<string, LanguageDefinition>
     */
    private static function buildIndex(): array
    {
        $index = [];

        foreach (self::catalogue() as $definition) {
            $index[$definition->code] = $definition;
        }

        return $index;
    }

    /**
     * The raw catalogue of all supported language definitions.
     *
     * RTL languages are flagged explicitly; all others default to LTR.
     *
     * @return list<LanguageDefinition>
     */
    private static function catalogue(): array
    {
        return [
            new LanguageDefinition(code: 'af', name: 'Afrikaans', nativeName: 'Afrikaans'),
            new LanguageDefinition(code: 'sq', name: 'Albanian', nativeName: 'Shqip'),
            new LanguageDefinition(code: 'am', name: 'Amharic', nativeName: 'አማርኛ'),
            new LanguageDefinition(code: 'ar', name: 'Arabic', nativeName: 'العربية', rtl: true),
            new LanguageDefinition(code: 'hy', name: 'Armenian', nativeName: 'Հայերեն'),
            new LanguageDefinition(code: 'as', name: 'Assamese', nativeName: 'অসমীয়া'),
            new LanguageDefinition(code: 'ay', name: 'Aymara', nativeName: 'Aymar aru'),
            new LanguageDefinition(code: 'az', name: 'Azerbaijani', nativeName: 'Azərbaycan'),
            new LanguageDefinition(code: 'bm', name: 'Bambara', nativeName: 'Bamanankan'),
            new LanguageDefinition(code: 'eu', name: 'Basque', nativeName: 'Euskara'),
            new LanguageDefinition(code: 'be', name: 'Belarusian', nativeName: 'Беларуская'),
            new LanguageDefinition(code: 'bn', name: 'Bengali', nativeName: 'বাংলা'),
            new LanguageDefinition(code: 'bho', name: 'Bhojpuri', nativeName: 'भोजपुरी'),
            new LanguageDefinition(code: 'bs', name: 'Bosnian', nativeName: 'Bosanski'),
            new LanguageDefinition(code: 'bg', name: 'Bulgarian', nativeName: 'Български'),
            new LanguageDefinition(code: 'ca', name: 'Catalan', nativeName: 'Català'),
            new LanguageDefinition(code: 'ceb', name: 'Cebuano', nativeName: 'Cebuano'),
            new LanguageDefinition(code: 'zh', name: 'Chinese (Simplified)', nativeName: '简体中文'),
            new LanguageDefinition(code: 'zh-Hant', name: 'Chinese (Traditional)', nativeName: '繁體中文'),
            new LanguageDefinition(code: 'co', name: 'Corsican', nativeName: 'Corsu'),
            new LanguageDefinition(code: 'hr', name: 'Croatian', nativeName: 'Hrvatski'),
            new LanguageDefinition(code: 'cs', name: 'Czech', nativeName: 'Čeština'),
            new LanguageDefinition(code: 'da', name: 'Danish', nativeName: 'Dansk'),
            new LanguageDefinition(code: 'dv', name: 'Divehi', nativeName: 'ދިވެހި', rtl: true),
            new LanguageDefinition(code: 'nl', name: 'Dutch', nativeName: 'Nederlands'),
            new LanguageDefinition(code: 'en', name: 'English', nativeName: 'English'),
            new LanguageDefinition(code: 'en-GB', name: 'English (United Kingdom)', nativeName: 'English (United Kingdom)'),
            new LanguageDefinition(code: 'en-US', name: 'English (United States)', nativeName: 'English (United States)'),
            new LanguageDefinition(code: 'eo', name: 'Esperanto', nativeName: 'Esperanto'),
            new LanguageDefinition(code: 'et', name: 'Estonian', nativeName: 'Eesti'),
            new LanguageDefinition(code: 'fil', name: 'Filipino', nativeName: 'Filipino'),
            new LanguageDefinition(code: 'fi', name: 'Finnish', nativeName: 'Suomi'),
            new LanguageDefinition(code: 'fr', name: 'French', nativeName: 'Français'),
            new LanguageDefinition(code: 'gl', name: 'Galician', nativeName: 'Galego'),
            new LanguageDefinition(code: 'ka', name: 'Georgian', nativeName: 'ქართული'),
            new LanguageDefinition(code: 'de', name: 'German', nativeName: 'Deutsch'),
            new LanguageDefinition(code: 'el', name: 'Greek', nativeName: 'Ελληνικά'),
            new LanguageDefinition(code: 'gn', name: 'Guarani', nativeName: "Avañe'ẽ"),
            new LanguageDefinition(code: 'gu', name: 'Gujarati', nativeName: 'ગુજરાતી'),
            new LanguageDefinition(code: 'ht', name: 'Haitian Creole', nativeName: 'Kreyòl Ayisyen'),
            new LanguageDefinition(code: 'ha', name: 'Hausa', nativeName: 'Hausa'),
            new LanguageDefinition(code: 'haw', name: 'Hawaiian', nativeName: 'ʻŌlelo Hawaiʻi'),
            new LanguageDefinition(code: 'he', name: 'Hebrew', nativeName: 'עברית', rtl: true),
            new LanguageDefinition(code: 'hi', name: 'Hindi', nativeName: 'हिन्दी'),
            new LanguageDefinition(code: 'hu', name: 'Hungarian', nativeName: 'Magyar'),
            new LanguageDefinition(code: 'is', name: 'Icelandic', nativeName: 'Íslenska'),
            new LanguageDefinition(code: 'ig', name: 'Igbo', nativeName: 'Igbo'),
            new LanguageDefinition(code: 'id', name: 'Indonesian', nativeName: 'Bahasa Indonesia'),
            new LanguageDefinition(code: 'ga', name: 'Irish', nativeName: 'Gaeilge'),
            new LanguageDefinition(code: 'it', name: 'Italian', nativeName: 'Italiano'),
            new LanguageDefinition(code: 'ja', name: 'Japanese', nativeName: '日本語'),
            new LanguageDefinition(code: 'jv', name: 'Javanese', nativeName: 'Jawa'),
            new LanguageDefinition(code: 'kn', name: 'Kannada', nativeName: 'ಕನ್ನಡ'),
            new LanguageDefinition(code: 'kk', name: 'Kazakh', nativeName: 'Қазақ тілі'),
            new LanguageDefinition(code: 'km', name: 'Khmer', nativeName: 'ខ្មែរ'),
            new LanguageDefinition(code: 'rw', name: 'Kinyarwanda', nativeName: 'Ikinyarwanda'),
            new LanguageDefinition(code: 'ko', name: 'Korean', nativeName: '한국어'),
            new LanguageDefinition(code: 'ku', name: 'Kurdish', nativeName: 'Kurdî'),
            new LanguageDefinition(code: 'ky', name: 'Kyrgyz', nativeName: 'Кыргызча'),
            new LanguageDefinition(code: 'lo', name: 'Lao', nativeName: 'ລາວ'),
            new LanguageDefinition(code: 'la', name: 'Latin', nativeName: 'Latina'),
            new LanguageDefinition(code: 'lv', name: 'Latvian', nativeName: 'Latviešu'),
            new LanguageDefinition(code: 'ln', name: 'Lingala', nativeName: 'Lingála'),
            new LanguageDefinition(code: 'lt', name: 'Lithuanian', nativeName: 'Lietuvių'),
            new LanguageDefinition(code: 'mk', name: 'Macedonian', nativeName: 'Македонски'),
            new LanguageDefinition(code: 'ms', name: 'Malay', nativeName: 'Bahasa Melayu'),
            new LanguageDefinition(code: 'ml', name: 'Malayalam', nativeName: 'മലയാളം'),
            new LanguageDefinition(code: 'mt', name: 'Maltese', nativeName: 'Malti'),
            new LanguageDefinition(code: 'mi', name: 'Maori', nativeName: 'Te Reo Māori'),
            new LanguageDefinition(code: 'mr', name: 'Marathi', nativeName: 'मराठी'),
            new LanguageDefinition(code: 'mn', name: 'Mongolian', nativeName: 'Монгол'),
            new LanguageDefinition(code: 'ne', name: 'Nepali', nativeName: 'नेपाली'),
            new LanguageDefinition(code: 'no', name: 'Norwegian', nativeName: 'Norsk'),
            new LanguageDefinition(code: 'ps', name: 'Pashto', nativeName: 'پښتو', rtl: true),
            new LanguageDefinition(code: 'fa', name: 'Persian', nativeName: 'فارسی', rtl: true),
            new LanguageDefinition(code: 'pl', name: 'Polish', nativeName: 'Polski'),
            new LanguageDefinition(code: 'pt', name: 'Portuguese', nativeName: 'Português'),
            new LanguageDefinition(code: 'pt-BR', name: 'Portuguese (Brazil)', nativeName: 'Português (Brasil)'),
            new LanguageDefinition(code: 'pt-PT', name: 'Portuguese (Portugal)', nativeName: 'Português (Portugal)'),
            new LanguageDefinition(code: 'pa', name: 'Punjabi', nativeName: 'ਪੰਜਾਬੀ'),
            new LanguageDefinition(code: 'ro', name: 'Romanian', nativeName: 'Română'),
            new LanguageDefinition(code: 'ru', name: 'Russian', nativeName: 'Русский'),
            new LanguageDefinition(code: 'sm', name: 'Samoan', nativeName: 'Gagana Samoa'),
            new LanguageDefinition(code: 'sr', name: 'Serbian', nativeName: 'Српски'),
            new LanguageDefinition(code: 'st', name: 'Sesotho', nativeName: 'Sesotho'),
            new LanguageDefinition(code: 'sn', name: 'Shona', nativeName: 'chiShona'),
            new LanguageDefinition(code: 'sd', name: 'Sindhi', nativeName: 'سنڌي', rtl: true),
            new LanguageDefinition(code: 'si', name: 'Sinhala', nativeName: 'සිංහල'),
            new LanguageDefinition(code: 'sk', name: 'Slovak', nativeName: 'Slovenčina'),
            new LanguageDefinition(code: 'sl', name: 'Slovenian', nativeName: 'Slovenščina'),
            new LanguageDefinition(code: 'so', name: 'Somali', nativeName: 'Soomaali'),
            new LanguageDefinition(code: 'es', name: 'Spanish', nativeName: 'Español'),
            new LanguageDefinition(code: 'su', name: 'Sundanese', nativeName: 'Basa Sunda'),
            new LanguageDefinition(code: 'sw', name: 'Swahili', nativeName: 'Kiswahili'),
            new LanguageDefinition(code: 'sv', name: 'Swedish', nativeName: 'Svenska'),
            new LanguageDefinition(code: 'tg', name: 'Tajik', nativeName: 'Тоҷикӣ'),
            new LanguageDefinition(code: 'ta', name: 'Tamil', nativeName: 'தமிழ்'),
            new LanguageDefinition(code: 'te', name: 'Telugu', nativeName: 'తెలుగు'),
            new LanguageDefinition(code: 'th', name: 'Thai', nativeName: 'ไทย'),
            new LanguageDefinition(code: 'tr', name: 'Turkish', nativeName: 'Türkçe'),
            new LanguageDefinition(code: 'tk', name: 'Turkmen', nativeName: 'Türkmen'),
            new LanguageDefinition(code: 'uk', name: 'Ukrainian', nativeName: 'Українська'),
            new LanguageDefinition(code: 'ur', name: 'Urdu', nativeName: 'اردو', rtl: true),
            new LanguageDefinition(code: 'uz', name: 'Uzbek', nativeName: "O'zbek"),
            new LanguageDefinition(code: 'vi', name: 'Vietnamese', nativeName: 'Tiếng Việt'),
            new LanguageDefinition(code: 'cy', name: 'Welsh', nativeName: 'Cymraeg'),
            new LanguageDefinition(code: 'xh', name: 'Xhosa', nativeName: 'isiXhosa'),
            new LanguageDefinition(code: 'yi', name: 'Yiddish', nativeName: 'ייִדיש', rtl: true),
            new LanguageDefinition(code: 'yo', name: 'Yoruba', nativeName: 'Yorùbá'),
            new LanguageDefinition(code: 'zu', name: 'Zulu', nativeName: 'isiZulu'),
        ];
    }
}
