<?php

declare(strict_types=1);

namespace Syriable\Translator\Enums;

/**
 * Represents the translation lifecycle status of a single Translation record.
 *
 * - Untranslated: No value has been provided yet.
 * - Translated:   A value has been supplied and is considered complete.
 * - Reviewed:     The value has been reviewed and approved for production use.
 *
 * Used as a cast on the Translation model and as the default inserted by the
 * import pipeline (TranslationKeyReplicator) and importer (TranslationImporter).
 */
enum TranslationStatus: string
{
    case Untranslated = 'untranslated';
    case Translated = 'translated';
    case Reviewed = 'reviewed';

    /**
     * Return a human-readable label for display in UIs and reports.
     */
    public function label(): string
    {
        return match ($this) {
            self::Untranslated => 'Untranslated',
            self::Translated => 'Translated',
            self::Reviewed => 'Reviewed',
        };
    }

    /**
     * Determine whether this status represents a completed translation.
     *
     * Used by Translation::isComplete() and ImportResult::hasChanges().
     */
    public function isComplete(): bool
    {
        return $this === self::Translated || $this === self::Reviewed;
    }
}
