<?php

declare(strict_types=1);

use Syriable\Translator\Services\Importer\TranslationDirectoryExplorer;

describe('TranslationDirectoryExplorer', function (): void {

    beforeEach(function (): void {
        $this->explorer = new TranslationDirectoryExplorer;
        $this->langDir = sys_get_temp_dir().'/translator_explorer_'.uniqid();

        // ── Directory structure ─────────────────────────────────────────────
        // lang/
        //   en/auth.php, en/validation.php
        //   fr/auth.php
        //   vendor/
        //     spatie/en/permissions.php
        //     spatie/fr/permissions.php

        $dirs = [
            $this->langDir.'/en',
            $this->langDir.'/fr',
            $this->langDir.'/vendor/spatie/en',
            $this->langDir.'/vendor/spatie/fr',
        ];

        foreach ($dirs as $dir) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->langDir.'/en/auth.php', "<?php\nreturn [];");
        file_put_contents($this->langDir.'/en/validation.php', "<?php\nreturn [];");
        file_put_contents($this->langDir.'/fr/auth.php', "<?php\nreturn [];");
        file_put_contents($this->langDir.'/vendor/spatie/en/permissions.php', "<?php\nreturn [];");
        file_put_contents($this->langDir.'/vendor/spatie/fr/permissions.php', "<?php\nreturn [];");
    });

    afterEach(function (): void {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->langDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->langDir);
    });

    // -------------------------------------------------------------------------
    // discoverLocales
    // -------------------------------------------------------------------------

    describe('discoverLocales()', function (): void {

        it('returns sorted locale codes from top-level directories', function (): void {
            $locales = $this->explorer->discoverLocales($this->langDir);

            expect($locales)->toBe(['en', 'fr']);
        });

        it('excludes the vendor directory from locale discovery', function (): void {
            $locales = $this->explorer->discoverLocales($this->langDir);

            expect($locales)->not->toContain('vendor');
        });

        it('returns an empty array when lang path does not exist', function (): void {
            expect($this->explorer->discoverLocales('/nonexistent'))->toBeEmpty();
        });
    });

    // -------------------------------------------------------------------------
    // discoverGroupFiles
    // -------------------------------------------------------------------------

    describe('discoverGroupFiles()', function (): void {

        it('returns PHP files keyed by group name', function (): void {
            $files = $this->explorer->discoverGroupFiles($this->langDir, 'en');

            expect($files)->toHaveKeys(['auth', 'validation'])
                ->and($files['auth'])->toEndWith('/en/auth.php')
                ->and($files['validation'])->toEndWith('/en/validation.php');
        });

        it('returns files sorted alphabetically by group name', function (): void {
            $keys = array_keys($this->explorer->discoverGroupFiles($this->langDir, 'en'));

            expect($keys)->toBe(['auth', 'validation']);
        });

        it('returns an empty array for a locale with no PHP files', function (): void {
            mkdir($this->langDir.'/de', 0755, true);

            $files = $this->explorer->discoverGroupFiles($this->langDir, 'de');

            expect($files)->toBeEmpty();

            rmdir($this->langDir.'/de');
        });

        it('returns an empty array when the locale directory does not exist', function (): void {
            expect($this->explorer->discoverGroupFiles($this->langDir, 'ja'))->toBeEmpty();
        });
    });

    // -------------------------------------------------------------------------
    // discoverVendorFiles
    // -------------------------------------------------------------------------

    describe('discoverVendorFiles()', function (): void {

        it('returns a nested namespace → locale → group structure', function (): void {
            $vendor = $this->explorer->discoverVendorFiles($this->langDir);

            expect($vendor)->toHaveKey('spatie')
                ->and($vendor['spatie'])->toHaveKeys(['en', 'fr'])
                ->and($vendor['spatie']['en'])->toHaveKey('permissions')
                ->and($vendor['spatie']['fr'])->toHaveKey('permissions');
        });

        it('returns an empty array when no vendor directory exists', function (): void {
            $langDir = sys_get_temp_dir().'/no_vendor_'.uniqid();
            mkdir($langDir.'/en', 0755, true);
            file_put_contents($langDir.'/en/auth.php', '<?php return [];');

            $result = $this->explorer->discoverVendorFiles($langDir);

            expect($result)->toBeEmpty();

            unlink($langDir.'/en/auth.php');
            rmdir($langDir.'/en');
            rmdir($langDir);
        });
    });
});
