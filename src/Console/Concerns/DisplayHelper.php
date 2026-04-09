<?php

declare(strict_types=1);

namespace Syriable\Translator\Console\Concerns;

use function Laravel\Prompts\info;

/**
 * Provides shared display utilities for Translator console commands.
 *
 * Centralises header rendering and output formatting so every command
 * in the package presents a consistent visual style.
 */
trait DisplayHelper
{
    /**
     * Render a styled section header with the package name and context label.
     *
     * Example output:
     *   ┌─────────────────────────────────┐
     *   │  🌐 Syriable Translator — Import │
     *   └─────────────────────────────────┘
     *
     * @param  string  $context  Label identifying the current operation (e.g. 'Import').
     */
    protected function displayHeader(string $context): void
    {
        info('🌐 Syriable Translator — '.$context);
    }
}
