<?php

declare(strict_types=1);

namespace Syriable\Translator\Exceptions\AI;

/**
 * Thrown when a provider rejects the request due to exceeding the rate limit
 * (HTTP 429).
 *
 * Callers may retry after a delay. When queued, the job should be released
 * back onto the queue with an appropriate backoff period.
 */
final class ProviderRateLimitException extends TranslationProviderException {}
