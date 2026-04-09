<?php

declare(strict_types=1);

namespace Syriable\Translator\AI;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Syriable\Translator\AI\Contracts\TranslationProviderInterface;
use Syriable\Translator\AI\Drivers\ChatGptDriver;
use Syriable\Translator\AI\Drivers\ClaudeDriver;
use Syriable\Translator\AI\Estimators\TokenEstimator;
use Syriable\Translator\AI\Prompts\TranslationPromptBuilder;

/**
 * Driver registry and factory for AI translation providers.
 *
 * Follows the Laravel Manager pattern to support multiple providers through
 * a unified interface. Drivers are resolved lazily — a provider instance is
 * only constructed when first requested.
 *
 * Built-in drivers:
 *  - `claude`  → ClaudeDriver (Anthropic)
 *
 * Custom drivers can be registered at runtime via extend():
 * ```php
 * TranslationProviderManager::extend('gemini', fn () => new GeminiDriver(...));
 * ```
 *
 * The default provider is read from `translator.ai.default_provider`.
 */
class TranslationProviderManager
{
    /**
     * Resolved provider instances, keyed by provider name.
     *
     * @var array<string, TranslationProviderInterface>
     */
    private array $resolved = [];

    /**
     * Custom driver factory closures registered at runtime.
     *
     * @var array<string, callable(): TranslationProviderInterface>
     */
    private array $customDrivers = [];

    public function __construct(
        private readonly TokenEstimator $estimator,
        private readonly TranslationPromptBuilder $promptBuilder,
        ?Container $container = null,
    ) {}

    /**
     * Resolve and return the driver for the given provider name.
     *
     * Returns a cached instance when the provider has already been resolved.
     *
     * @param  string|null  $provider  Provider name, or null to use the configured default.
     *
     * @throws InvalidArgumentException When no driver exists for the given provider name.
     */
    public function driver(?string $provider = null): TranslationProviderInterface
    {
        $name = $provider ?? $this->defaultProvider();

        if (! isset($this->resolved[$name])) {
            $this->resolved[$name] = $this->createDriver($name);
        }

        return $this->resolved[$name];
    }

    /**
     * Register a custom driver factory for the given provider name.
     *
     * The factory receives no arguments — dependencies should be captured
     * in the closure or resolved from the container inside it.
     *
     * @param  string  $name  Canonical provider name (e.g. 'gemini', 'chatgpt').
     * @param  callable  $factory  Factory that returns a TranslationProviderInterface instance.
     */
    public function extend(string $name, callable $factory): self
    {
        $this->customDrivers[$name] = $factory;

        // Invalidate any cached instance for this provider.
        unset($this->resolved[$name]);

        return $this;
    }

    /**
     * Return all provider names that have registered drivers.
     *
     * @return string[]
     */
    public function availableProviders(): array
    {
        return array_unique([
            'claude',
            'chatgpt',
            ...array_keys($this->customDrivers),
        ]);
    }

    /**
     * Create and return a driver instance for the given provider name.
     *
     * @throws InvalidArgumentException When the provider name is not recognised.
     */
    private function createDriver(string $name): TranslationProviderInterface
    {
        // Custom drivers take precedence over built-in ones.
        if (isset($this->customDrivers[$name])) {
            return ($this->customDrivers[$name])();
        }

        return match ($name) {
            'claude' => new ClaudeDriver($this->estimator, $this->promptBuilder),
            'chatgpt' => new ChatGptDriver($this->estimator, $this->promptBuilder),
            default => throw new InvalidArgumentException(
                "AI translation provider [{$name}] is not supported. ".
                'Available providers: '.implode(', ', $this->availableProviders()).'.',
            ),
        };
    }

    /**
     * Resolve the default provider name from configuration.
     */
    private function defaultProvider(): string
    {
        return (string) config('translator.ai.default_provider', 'claude');
    }
}
