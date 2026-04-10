<?php

declare(strict_types=1);

use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

describe('translator:scan command', function (): void {

    beforeEach(function (): void {
        // Build a temporary source directory with PHP files containing
        // various translation call forms.
        $this->sourceDir = sys_get_temp_dir().'/translator_scan_'.uniqid();
        mkdir($this->sourceDir.'/Http/Controllers', 0755, recursive: true);
        mkdir($this->sourceDir.'/resources/views', 0755, recursive: true);

        config([
            'translator.scanner.paths' => [$this->sourceDir],
            'translator.scanner.ignore_paths' => ['vendor', 'node_modules'],
            'translator.scanner.extensions' => ['php', 'blade.php'],
            'translator.source_language' => 'en',
        ]);

        // Seed a source language so replicateSingleKey() works in --sync tests.
        Language::factory()->english()->create();
    });

    afterEach(function (): void {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->sourceDir);
    });

    // -------------------------------------------------------------------------
    // Clean state
    // -------------------------------------------------------------------------

    it('exits with SUCCESS and reports no issues when code and DB are in sync', function (): void {
        // Source file references auth.failed.
        file_put_contents(
            $this->sourceDir.'/Http/Controllers/AuthController.php',
            "<?php echo __('auth.failed');",
        );

        // DB has the matching record.
        $group = Group::factory()->auth()->create();
        TranslationKey::factory()->create(['group_id' => $group->id, 'key' => 'failed']);

        $this->artisan('translator:scan')
            ->expectsOutputToContain('No missing or orphaned keys')
            ->assertExitCode(0);
    });

    // -------------------------------------------------------------------------
    // Missing keys
    // -------------------------------------------------------------------------

    it('reports missing keys when code calls a key absent from the DB', function (): void {
        file_put_contents(
            $this->sourceDir.'/Http/Controllers/AuthController.php',
            "<?php echo __('auth.failed'); echo __('auth.new_key');",
        );

        // Only 'failed' is in DB; 'new_key' is missing.
        $group = Group::factory()->auth()->create();
        TranslationKey::factory()->create(['group_id' => $group->id, 'key' => 'failed']);

        $this->artisan('translator:scan')
            ->expectsOutputToContain('auth.new_key')
            ->assertExitCode(0);
    });

    it('exits with code 1 when --fail-on-missing is used and missing keys exist', function (): void {
        file_put_contents(
            $this->sourceDir.'/Http/Controllers/AuthController.php',
            "<?php echo __('auth.missing');",
        );

        $this->artisan('translator:scan --fail-on-missing')
            ->assertExitCode(1);
    });

    it('exits with SUCCESS under --fail-on-missing when no missing keys exist', function (): void {
        file_put_contents(
            $this->sourceDir.'/Http/Controllers/AuthController.php',
            "<?php echo __('auth.failed');",
        );

        $group = Group::factory()->auth()->create();
        TranslationKey::factory()->create(['group_id' => $group->id, 'key' => 'failed']);

        $this->artisan('translator:scan --fail-on-missing')
            ->assertExitCode(0);
    });

    // -------------------------------------------------------------------------
    // Orphaned keys
    // -------------------------------------------------------------------------

    it('reports orphaned keys when the DB has keys not referenced in code', function (): void {
        // Code only references auth.failed.
        file_put_contents(
            $this->sourceDir.'/Http/Controllers/AuthController.php',
            "<?php echo __('auth.failed');",
        );

        // DB has both 'failed' and 'password' — 'password' is orphaned.
        $group = Group::factory()->auth()->create();
        TranslationKey::factory()->create(['group_id' => $group->id, 'key' => 'failed']);
        TranslationKey::factory()->create(['group_id' => $group->id, 'key' => 'password']);

        $this->artisan('translator:scan')
            ->expectsOutputToContain('auth.password')
            ->assertExitCode(0);
    });

    it('does not report vendor-namespaced keys as orphaned', function (): void {
        // Code has nothing.
        file_put_contents(
            $this->sourceDir.'/Http/Controllers/AuthController.php',
            '<?php // no translation calls',
        );

        // DB has a vendor-namespaced key.
        $vendorGroup = Group::factory()->vendor('spatie')->create(['name' => 'permissions']);
        TranslationKey::factory()->create(['group_id' => $vendorGroup->id, 'key' => 'role.created']);

        // Vendor keys should be excluded from the orphan report entirely.
        $this->artisan('translator:scan')
            ->expectsOutputToContain('No missing or orphaned keys')
            ->assertExitCode(0);
    });

    // -------------------------------------------------------------------------
    // --missing-only and --orphans-only flags
    // -------------------------------------------------------------------------

    it('shows only missing keys when --missing-only is passed', function (): void {
        file_put_contents(
            $this->sourceDir.'/Http/Controllers/AuthController.php',
            "<?php echo __('auth.missing');",
        );

        $group = Group::factory()->auth()->create();
        TranslationKey::factory()->create(['group_id' => $group->id, 'key' => 'orphaned']);

        $this->artisan('translator:scan --missing-only')
            ->expectsOutputToContain('auth.missing')
            ->assertExitCode(0);
    });

    // -------------------------------------------------------------------------
    // --sync flag
    // -------------------------------------------------------------------------

    it('inserts missing TranslationKey records when --sync is passed', function (): void {
        file_put_contents(
            $this->sourceDir.'/Http/Controllers/AuthController.php',
            "<?php echo __('auth.new_key');",
        );

        // Group exists but key does not.
        Group::factory()->auth()->create();

        expect(TranslationKey::where('key', 'new_key')->exists())->toBeFalse();

        $this->artisan('translator:scan --sync --no-interaction')
            ->assertExitCode(0);

        expect(TranslationKey::where('key', 'new_key')->exists())->toBeTrue();
    });

    it('creates the Group when it does not exist during --sync', function (): void {
        file_put_contents(
            $this->sourceDir.'/Http/Controllers/AuthController.php',
            "<?php echo __('newgroup.some_key');",
        );

        expect(Group::where('name', 'newgroup')->exists())->toBeFalse();

        $this->artisan('translator:scan --sync --no-interaction')
            ->assertExitCode(0);

        expect(Group::where('name', 'newgroup')->exists())->toBeTrue()
            ->and(TranslationKey::where('key', 'some_key')->exists())->toBeTrue();
    });

    it('replicates synced keys to all active languages', function (): void {
        Language::factory()->french()->create();

        file_put_contents(
            $this->sourceDir.'/Http/Controllers/AuthController.php',
            "<?php echo __('auth.synced_key');",
        );

        Group::factory()->auth()->create();

        $this->artisan('translator:scan --sync --no-interaction')
            ->assertExitCode(0);

        $key = TranslationKey::where('key', 'synced_key')->first();
        expect($key)->not->toBeNull()
            ->and(Translation::where('translation_key_id', $key->id)->count())->toBe(2);
    });

    it('does not duplicate TranslationKey records on repeated --sync runs', function (): void {
        file_put_contents(
            $this->sourceDir.'/Http/Controllers/AuthController.php',
            "<?php echo __('auth.idempotent_key');",
        );

        Group::factory()->auth()->create();

        $this->artisan('translator:scan --sync --no-interaction')->assertExitCode(0);
        $this->artisan('translator:scan --sync --no-interaction')->assertExitCode(0);

        expect(TranslationKey::where('key', 'idempotent_key')->count())->toBe(1);
    });

    // -------------------------------------------------------------------------
    // JSON keys (no dot prefix)
    // -------------------------------------------------------------------------

    it('correctly identifies JSON-style keys (no dot prefix) as belonging to _json group', function (): void {
        file_put_contents(
            $this->sourceDir.'/resources/views/welcome.blade.php',
            "{{ __('Welcome to our app') }}",
        );

        $jsonGroup = Group::factory()->json()->create();
        TranslationKey::factory()->create([
            'group_id' => $jsonGroup->id,
            'key' => 'Welcome to our app',
        ]);

        $this->artisan('translator:scan')
            ->expectsOutputToContain('No missing or orphaned keys')
            ->assertExitCode(0);
    });
});
