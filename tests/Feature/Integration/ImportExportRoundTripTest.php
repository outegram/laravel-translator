<?php

declare(strict_types=1);

use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\TranslationKey;

describe('Import → Export round-trip', function (): void {

    beforeEach(function (): void {
        $this->langDir = sys_get_temp_dir().'/translator_roundtrip_'.uniqid();

        // ── Source structure ───────────────────────────────────────────────
        // lang/
        //   en/
        //     auth.php         (PHP group file)
        //     messages.php     (PHP group file, nested keys)
        //   en.json            (JSON locale file)

        $enDir = $this->langDir.'/en';
        mkdir($enDir, 0755, true);

        file_put_contents($enDir.'/auth.php', <<<'PHP'
        <?php
        return [
            'failed'   => 'These credentials do not match our records.',
            'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
        ];
        PHP);

        file_put_contents($enDir.'/messages.php', <<<'PHP'
        <?php
        return [
            'inbox' => [
                'empty' => 'Your inbox is empty.',
                'count' => 'You have :count messages.',
            ],
        ];
        PHP);

        file_put_contents($this->langDir.'/en.json', json_encode([
            'Welcome' => 'Welcome to our application.',
            'Goodbye' => 'See you soon!',
        ], JSON_PRETTY_PRINT));

        config([
            'translator.lang_path' => $this->langDir,
            'translator.source_language' => 'en',
        ]);
    });

    afterEach(function (): void {
        // Recursively remove the temp lang directory.
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->langDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->langDir);
    });

    it('imports all keys from PHP and JSON files', function (): void {
        $this->artisan('translator:import')->assertExitCode(0);

        // PHP group keys (dot-notation from nested arrays).
        expect(TranslationKey::where('key', 'failed')->exists())->toBeTrue()
            ->and(TranslationKey::where('key', 'throttle')->exists())->toBeTrue()
            ->and(TranslationKey::where('key', 'inbox.empty')->exists())->toBeTrue()
            ->and(TranslationKey::where('key', 'inbox.count')->exists())->toBeTrue()
            // JSON top-level keys.
            ->and(TranslationKey::where('key', 'Welcome')->exists())->toBeTrue()
            ->and(TranslationKey::where('key', 'Goodbye')->exists())->toBeTrue();
    });

    it('exports PHP group files that can be re-imported identically', function (): void {
        // Step 1 — Import from disk.
        $this->artisan('translator:import')->assertExitCode(0);

        $originalKeyCount = TranslationKey::count();

        // Step 2 — Remove the source files (test that export regenerates them).
        unlink($this->langDir.'/en/auth.php');
        unlink($this->langDir.'/en/messages.php');

        // Step 3 — Export back to disk.
        $this->artisan('translator:export --locale=en')->assertExitCode(0);

        // Verify the exported files exist.
        expect(file_exists($this->langDir.'/en/auth.php'))->toBeTrue()
            ->and(file_exists($this->langDir.'/en/messages.php'))->toBeTrue();

        // Step 4 — Verify the exported file content is correct.
        $auth = require $this->langDir.'/en/auth.php';
        expect($auth['failed'])->toBe('These credentials do not match our records.')
            ->and($auth['throttle'])->toBe('Too many login attempts. Please try again in :seconds seconds.');

        $messages = require $this->langDir.'/en/messages.php';
        expect($messages['inbox']['empty'])->toBe('Your inbox is empty.')
            ->and($messages['inbox']['count'])->toBe('You have :count messages.');
    });

    it('exports JSON locale files that match the original', function (): void {
        $this->artisan('translator:import')->assertExitCode(0);
        unlink($this->langDir.'/en.json');

        $this->artisan('translator:export --locale=en')->assertExitCode(0);

        $exported = json_decode(file_get_contents($this->langDir.'/en.json'), associative: true);
        expect($exported['Welcome'])->toBe('Welcome to our application.')
            ->and($exported['Goodbye'])->toBe('See you soon!');
    });

    it('detects parameter tokens in imported keys', function (): void {
        $this->artisan('translator:import')->assertExitCode(0);

        $throttleKey = TranslationKey::where('key', 'throttle')->first();
        expect($throttleKey->hasParameters())->toBeTrue()
            ->and($throttleKey->parameterNames())->toContain(':seconds');

        $inboxCount = TranslationKey::where('key', 'inbox.count')->first();
        expect($inboxCount->hasParameters())->toBeTrue()
            ->and($inboxCount->parameterNames())->toContain(':count');
    });

    it('marks the source language as active and is_source', function (): void {
        $this->artisan('translator:import')->assertExitCode(0);

        $en = Language::where('code', 'en')->first();
        expect($en->active)->toBeTrue()
            ->and($en->is_source)->toBeTrue();
    });

    it('produces identical file content on repeated import → export cycles', function (): void {
        // Cycle 1.
        $this->artisan('translator:import')->assertExitCode(0);
        $this->artisan('translator:export --locale=en')->assertExitCode(0);
        $firstExport = require $this->langDir.'/en/auth.php';

        // Cycle 2.
        $this->artisan('translator:import')->assertExitCode(0);
        $this->artisan('translator:export --locale=en')->assertExitCode(0);
        $secondExport = require $this->langDir.'/en/auth.php';

        expect($firstExport)->toBe($secondExport);
    });
});
