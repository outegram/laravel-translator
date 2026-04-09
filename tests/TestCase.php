<?php

declare(strict_types=1);

namespace Syriable\Translator\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Syriable\Translator\TranslatorServiceProvider;

/**
 * Base test case for all Syriable Translator tests.
 *
 * Bootstraps the package service provider and sets up an in-memory SQLite
 * database so tests run in isolation without requiring an external database.
 */
abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/orchestra/testbench-core/laravel/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            TranslatorServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Use SQLite in-memory for fast, isolated test runs.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Use a consistent source language for all tests.
        $app['config']->set('translator.source_language', 'en');

        // Disable AI cache by default so tests don't depend on cache state.
        $app['config']->set('translator.ai.cache.enabled', false);

        // Use array cache driver in tests.
        $app['config']->set('cache.default', 'array');
    }
}
