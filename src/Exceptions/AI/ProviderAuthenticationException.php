<?php

declare(strict_types=1);

namespace Syriable\Translator\Exceptions\AI;

/**
 * Thrown when a provider rejects the request due to an invalid, missing,
 * or expired API key (HTTP 401 / 403).
 *
 * Callers should surface this immediately to the user rather than retrying,
 * as the error is caused by misconfiguration rather than a transient failure.
 */
final class ProviderAuthenticationException extends TranslationProviderException {}
