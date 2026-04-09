<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\Exporter;

use JsonException;
use Syriable\Translator\Services\Exporter\Concerns\EnsuresDirectory;

/**
 * Writes a flat translation array to a JSON locale file.
 *
 * Produces UTF-8 encoded JSON with optional pretty-printing and key sorting,
 * matching the format expected by Laravel's JSON translation loader.
 *
 * The output file always ends with a newline for POSIX compliance and to
 * avoid spurious diffs in version control.
 */
final class JsonFileWriter
{
    use EnsuresDirectory;

    /**
     * Write a translation map to a JSON locale file.
     *
     * @param  string  $filePath  Absolute path to the output `.json` file.
     * @param  array<string, mixed>  $translations  Key-value translation pairs.
     * @param  bool  $sortKeys  Sort keys alphabetically before writing.
     * @param  bool  $prettyPrint  Format output with indentation and newlines.
     * @param  bool  $unescapeUnicode  Write Unicode characters as-is (e.g. Arabic, CJK).
     * @return bool True on success; false when the file could not be written.
     */
    public function write(
        string $filePath,
        array $translations,
        bool $sortKeys = true,
        bool $prettyPrint = true,
        bool $unescapeUnicode = true,
    ): bool {
        if ($sortKeys) {
            ksort($translations);
        }

        try {
            $encoded = json_encode(
                $translations,
                $this->resolveJsonFlags($prettyPrint, $unescapeUnicode),
            );
        } catch (JsonException) {
            return false;
        }

        $this->ensureDirectory(dirname($filePath));

        return file_put_contents($filePath, $encoded."\n") !== false;
    }

    /**
     * Resolve the bitmask of JSON encoding flags from the given options.
     *
     * JSON_THROW_ON_ERROR is always included so encoding failures throw a
     * catchable exception rather than returning false silently.
     */
    private function resolveJsonFlags(bool $prettyPrint, bool $unescapeUnicode): int
    {
        return JSON_THROW_ON_ERROR
            | ($prettyPrint ? JSON_PRETTY_PRINT : 0)
            | ($unescapeUnicode ? JSON_UNESCAPED_UNICODE : 0);
    }
}
