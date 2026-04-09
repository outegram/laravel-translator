<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\Exporter\Concerns;

/**
 * Provides a shared utility for ensuring output directories exist before writing.
 *
 * Used by PhpFileWriter and JsonFileWriter to avoid duplicating directory
 * creation logic. The trait is intentionally narrow — only the using class
 * should call ensureDirectory().
 */
trait EnsuresDirectory
{
    /**
     * Create the given directory path recursively when it does not yet exist.
     *
     * Uses 0755 permissions, which allows the web server to read files while
     * restricting writes to the owner. Recursive creation handles arbitrarily
     * deep paths (e.g. `lang/vendor/spatie/en/`) in a single call.
     *
     * @param  string  $path  Absolute path to the directory to create.
     */
    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}
