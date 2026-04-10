<?php

declare(strict_types=1);

namespace Syriable\Translator\DTOs;

/**
 * Immutable value object encapsulating all runtime configuration for an export operation.
 */
final readonly class ExportOptions
{
    public function __construct(
        public ?string $locale = null,
        public ?string $group = null,
        public bool $sortKeys = true,
        public bool $requireApproval = false,
        public bool $dryRun = false,
        public string $source = 'cli',
        public ?string $triggeredBy = null,
    ) {}

    public static function fromConfig(array $overrides = []): self
    {
        return new self(
            locale: $overrides['locale'] ?? null,
            group: $overrides['group'] ?? null,
            sortKeys: config('translator.export.sort_keys', true),
            requireApproval: config('translator.export.require_approval', false),
            dryRun: $overrides['dry_run'] ?? false,
            source: $overrides['source'] ?? 'cli',
            triggeredBy: $overrides['triggered_by'] ?? null,
        );
    }
}
