<?php

declare(strict_types=1);

namespace Syriable\Translator\Exceptions\AI;

use RuntimeException;
use Throwable;

/**
 * Base exception for all AI translation provider failures.
 *
 * Provides a consistent structure for catching and handling provider errors
 * at the service layer, regardless of which underlying provider failed.
 */
class TranslationProviderException extends RuntimeException
{
    /**
     * @param  string  $provider  The canonical provider name that failed (e.g. 'claude').
     * @param  string  $message  Human-readable description of the failure.
     * @param  Throwable|null  $previous  The underlying exception that caused this failure.
     */
    public function __construct(
        public readonly string $provider,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: "[{$provider}] {$message}",
            previous: $previous,
        );
    }
}
