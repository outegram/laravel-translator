<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\Exporter;

use Syriable\Translator\Services\Exporter\Concerns\EnsuresDirectory;

/**
 * Writes a flat dot-notation translation array to a PHP translation file.
 *
 * Handles the full round-trip from flat key-value pairs (as stored in the
 * database) back to the nested PHP array format expected by Laravel's
 * translation loader.
 *
 * Responsibilities:
 *  - Unflattening dot-notation keys into a nested array structure.
 *  - Optionally sorting keys recursively for deterministic diffs.
 *  - Rendering the nested array as valid, indented PHP source code.
 *  - Ensuring the target directory exists before writing.
 */
final class PhpFileWriter
{
    use EnsuresDirectory;

    /**
     * Write a flat dot-notation translation map to a PHP translation file.
     *
     * The output is a valid `<?php return [...]; ?>` file compatible with
     * Laravel's file-based translation loader.
     *
     * @param  string  $filePath  Absolute path to the output `.php` file.
     * @param  array<string, mixed>  $translations  Flat dot-notation key-value translation pairs.
     * @param  bool  $sortKeys  Sort keys recursively before writing.
     * @return bool True on success; false when the file could not be written.
     */
    public function write(string $filePath, array $translations, bool $sortKeys = true): bool
    {
        $nested = $this->unflatten($translations);

        if ($sortKeys) {
            $this->sortRecursively($nested);
        }

        $content = "<?php\n\nreturn ".$this->renderArray($nested).";\n";

        $this->ensureDirectory(dirname($filePath));

        return file_put_contents($filePath, $content) !== false;
    }

    /**
     * Convert a flat dot-notation array into a nested associative array.
     *
     * Uses Laravel's `data_set()` helper to handle arbitrarily deep keys.
     *
     * Example:
     *   Input:  ['auth.failed' => 'Invalid credentials.']
     *   Output: ['auth' => ['failed' => 'Invalid credentials.']]
     *
     * @param  array<string, mixed>  $dotted  Flat dot-notation key-value pairs.
     * @return array<string, mixed> Nested associative array.
     */
    public function unflatten(array $dotted): array
    {
        $result = [];

        foreach ($dotted as $key => $value) {
            data_set($result, $key, $value);
        }

        return $result;
    }

    /**
     * Render a nested PHP array as a formatted string for a PHP source file.
     *
     * Uses 4-space indentation per level and `var_export()` for scalar values,
     * producing output that is human-readable and diff-friendly.
     *
     * @param  array<string, mixed>  $array  The array to render.
     * @param  int  $depth  Current indentation depth (1 = top level).
     * @return string Rendered PHP array literal.
     */
    private function renderArray(array $array, int $depth = 1): string
    {
        if (empty($array)) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth);
        $closingPad = str_repeat('    ', $depth - 1);
        $lines = [];

        foreach ($array as $key => $value) {
            $renderedKey = var_export($key, true);
            $renderedValue = is_array($value)
                ? $this->renderArray($value, $depth + 1)
                : var_export($value, true);

            $lines[] = $indent.$renderedKey.' => '.$renderedValue.',';
        }

        return "[\n".implode("\n", $lines)."\n".$closingPad.']';
    }

    /**
     * Sort a nested array by keys at every level of depth.
     *
     * Applied recursively so that all nested groups are sorted, producing
     * deterministic file output regardless of database insertion order.
     *
     * @param  array<string, mixed>  $array  The array to sort in place.
     */
    private function sortRecursively(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->sortRecursively($value);
            }
        }
    }
}
