<?php

declare(strict_types=1);

namespace Syriable\Translator\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Syriable\Translator\AI\TranslationProviderManager;
use Syriable\Translator\Console\Concerns\DisplayHelper;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\Exceptions\AI\TranslationProviderException;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Artisan command that verifies AI provider configuration and connectivity.
 *
 * Performs two levels of checks:
 *  1. Configuration check (always): Verifies the API key is present and the
 *     provider config block is correctly structured.
 *  2. Live ping (with --ping): Sends a minimal translation request to verify
 *     the API responds correctly. Uses the cheapest model where possible.
 *
 * Usage:
 * ```bash
 * php artisan translator:provider-check
 * php artisan translator:provider-check --provider=claude
 * php artisan translator:provider-check --all
 * php artisan translator:provider-check --provider=claude --ping
 * ```
 */
final class ProviderCheckCommand extends Command
{
    use DisplayHelper;

    protected $signature = 'translator:provider-check
        {--provider= : Check a specific provider by name (e.g. claude, chatgpt)}
        {--all       : Check all providers listed in config}
        {--ping      : Send a minimal live API request to verify connectivity}';

    protected $description = 'Check AI provider configuration and optionally verify live connectivity';

    public function handle(TranslationProviderManager $manager): int
    {
        $this->displayHeader('Provider Check');

        $providers = $this->resolveProviderNames();

        if (empty($providers)) {
            error('No providers specified. Use --provider=claude or --all.');

            return self::FAILURE;
        }

        $allPassed = true;

        foreach ($providers as $providerName) {
            $passed = $this->checkProvider($manager, $providerName);

            if (! $passed) {
                $allPassed = false;
            }
        }

        $this->newLine();

        if ($allPassed) {
            info('✅ All provider checks passed.');
        } else {
            error('Some provider checks failed. See details above.');
        }

        return $allPassed ? self::SUCCESS : self::FAILURE;
    }

    // -------------------------------------------------------------------------
    // Provider Checks
    // -------------------------------------------------------------------------

    private function checkProvider(TranslationProviderManager $manager, string $providerName): bool
    {
        $this->newLine();
        $this->line("<comment>Provider: {$providerName}</comment>");

        // 1. Check if the provider is configured in config/translator.php
        $configKey = "translator.ai.providers.{$providerName}";
        $providerConfig = config($configKey);

        if (! is_array($providerConfig)) {
            error("  ✗ No configuration block found at config('{$configKey}').");
            $this->line('  Add the provider to config/translator.php under ai.providers.*');

            return false;
        }

        info('  ✓ Configuration block exists.');

        // 2. Check API key presence
        $apiKey = config("{$configKey}.api_key");

        if (blank($apiKey)) {
            $envMap = [
                'claude' => 'ANTHROPIC_API_KEY',
                'chatgpt' => 'OPENAI_API_KEY',
            ];

            $envVar = $envMap[$providerName] ?? strtoupper($providerName).'_API_KEY';
            error("  ✗ API key is not configured. Set {$envVar} in your .env file.");

            return false;
        }

        info('  ✓ API key is present.');

        // 3. Check driver availability via manager
        try {
            $driver = $manager->driver($providerName);

            if (! $driver->isAvailable()) {
                error('  ✗ Provider reports itself as unavailable (isAvailable() = false).');

                return false;
            }

            info('  ✓ Provider is available.');
        } catch (InvalidArgumentException $e) {
            error("  ✗ No driver registered for [{$providerName}]. ".
                'Register it via TranslationProviderManager::extend().');

            return false;
        }

        // 4. Live API ping (optional)
        if ($this->option('ping')) {
            return $this->pingProvider($manager, $providerName);
        }

        $model = config("{$configKey}.model", 'unknown');
        $this->line("  Model: {$model}");
        $this->line('  Tip: Use --ping to verify live API connectivity.');

        return true;
    }

    private function pingProvider(TranslationProviderManager $manager, string $providerName): bool
    {
        $this->line('  Sending minimal ping request...');

        $request = new TranslationRequest(
            sourceLanguage: 'en',
            targetLanguage: 'fr',
            keys: ['ping' => 'Hello.'],
            groupName: '__ping',
        );

        try {
            $driver = $manager->driver($providerName);
            $response = $driver->translate($request);

            if ($response->translatedCount() > 0) {
                info("  ✓ Live ping successful. Response: \"{$response->translations['ping']}\"");
                $this->line("  Input tokens: {$response->inputTokensUsed} | Output tokens: {$response->outputTokensUsed}");

                return true;
            }

            warning('  ⚠  Ping returned no translations. The API responded but produced no output.');

            return false;
        } catch (TranslationProviderException $e) {
            error("  ✗ API call failed: {$e->getMessage()}");

            return false;
        } catch (Throwable $e) {
            error("  ✗ Unexpected error: {$e->getMessage()}");

            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Provider Resolution
    // -------------------------------------------------------------------------

    /**
     * @return string[]
     */
    private function resolveProviderNames(): array
    {
        if ($this->option('all')) {
            /** @var array<string, mixed> $providers */
            $providers = config('translator.ai.providers', []);

            return array_keys($providers);
        }

        $name = $this->option('provider');

        if (! blank($name)) {
            return [(string) $name];
        }

        // Default to the configured default provider when neither --all nor --provider is given.
        return [(string) config('translator.ai.default_provider', 'claude')];
    }
}
