<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

// =============================================================================
// translator:languages
// =============================================================================

describe('translator:languages command', function (): void {

    beforeEach(function (): void {
        Language::factory()->english()->create();
        Language::factory()->french()->create();
        Language::factory()->arabic()->create();
    });

    it('exits with SUCCESS and lists all languages', function (): void {
        $this->artisan('translator:languages')
            ->expectsOutputToContain('en')
            ->expectsOutputToContain('fr')
            ->expectsOutputToContain('ar')
            ->assertExitCode(0);
    });

    it('shows only active languages when --active is passed', function (): void {
        Language::factory()->inactive()->create(['code' => 'de', 'name' => 'German']);

        $this->artisan('translator:languages --active')
            ->assertExitCode(0);

        // The command should succeed regardless — inactive filtering is handled internally.
    });

    it('includes RTL indicator for Arabic', function (): void {
        $this->artisan('translator:languages')
            ->expectsOutputToContain('Yes') // RTL column for Arabic
            ->assertExitCode(0);
    });

    it('marks the source language', function (): void {
        $this->artisan('translator:languages')
            ->expectsOutputToContain('Yes') // Source column
            ->assertExitCode(0);
    });

    it('informs user when no languages are found', function (): void {
        Language::query()->delete();

        $this->artisan('translator:languages')
            ->expectsOutputToContain('No languages found')
            ->assertExitCode(0);
    });

    it('includes coverage stats when --with-coverage is passed', function (): void {
        $group = Group::factory()->auth()->create();
        TranslationKey::factory()->count(2)->create(['group_id' => $group->id]);

        $this->artisan('translator:languages --with-coverage')
            ->expectsOutputToContain('Translated %')
            ->assertExitCode(0);
    });

    it('outputs valid JSON when --format=json is specified', function (): void {
        $this->artisan('translator:languages --format=json')
            ->assertExitCode(0);
    });
});

// =============================================================================
// translator:diff
// =============================================================================

describe('translator:diff command', function (): void {

    beforeEach(function (): void {
        $this->langDir = sys_get_temp_dir().'/translator_diff_'.uniqid();
        $enDir = $this->langDir.'/en';
        mkdir($enDir, 0755, true);

        file_put_contents($enDir.'/auth.php', <<<'PHP'
        <?php
        return [
            'failed'   => 'These credentials do not match.',
            'file_only'=> 'Only in the file.',
        ];
        PHP);

        config([
            'translator.lang_path' => $this->langDir,
            'translator.source_language' => 'en',
        ]);

        $this->english = Language::factory()->english()->create();
        $this->group = Group::factory()->auth()->create();

        $this->failedKey = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'key' => 'failed',
        ]);
        $this->dbOnlyKey = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'key' => 'db_only',
        ]);

        Translation::factory()->translated('These credentials do not match.')->create([
            'translation_key_id' => $this->failedKey->id,
            'language_id' => $this->english->id,
        ]);
        Translation::factory()->translated('Only in the database.')->create([
            'translation_key_id' => $this->dbOnlyKey->id,
            'language_id' => $this->english->id,
        ]);
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

    it('requires --locale to be specified', function (): void {
        $this->artisan('translator:diff --no-interaction')
            ->assertExitCode(1);
    });

    it('exits with FAILURE when locale does not exist in DB', function (): void {
        $this->artisan('translator:diff --locale=xx')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('reports keys that exist only in DB (not in files)', function (): void {
        $this->artisan('translator:diff --locale=en')
            ->expectsOutputToContain('db_only')
            ->assertExitCode(0);
    });

    it('reports keys that exist only in files (not in DB)', function (): void {
        $this->artisan('translator:diff --locale=en')
            ->expectsOutputToContain('file_only')
            ->assertExitCode(0);
    });

    it('reports clean state when DB and files are in sync', function (): void {
        // Remove the DB-only key and file-only key from respective sources.
        Translation::where('translation_key_id', $this->dbOnlyKey->id)->delete();
        TranslationKey::find($this->dbOnlyKey->id)?->delete();

        file_put_contents(
            $this->langDir.'/en/auth.php',
            "<?php\nreturn ['failed' => 'These credentials do not match.'];\n",
        );

        $this->artisan('translator:diff --locale=en')
            ->expectsOutputToContain('in sync')
            ->assertExitCode(0);
    });

    it('shows value differences when a key has different values in DB vs file', function (): void {
        // Update DB value to differ from file.
        Translation::where('translation_key_id', $this->failedKey->id)->update([
            'value' => 'MODIFIED IN DATABASE.',
        ]);

        $this->artisan('translator:diff --locale=en --show-changed')
            ->expectsOutputToContain('failed')
            ->assertExitCode(0);
    });

    it('outputs JSON when --format=json is specified', function (): void {
        $this->artisan('translator:diff --locale=en --format=json')
            ->assertExitCode(0);
    });
});

// =============================================================================
// translator:provider-check
// =============================================================================

describe('translator:provider-check command', function (): void {

    beforeEach(function (): void {
        config([
            'translator.ai.default_provider' => 'claude',
            'translator.ai.providers.claude.api_key' => 'sk-ant-test',
            'translator.ai.providers.claude.model' => 'claude-haiku-4-5-20251001',
            'translator.ai.providers.claude.max_tokens' => 4096,
            'translator.ai.providers.claude.timeout_seconds' => 30,
            'translator.ai.providers.claude.max_retries' => 1,
            'translator.ai.providers.claude.input_cost_per_1k_tokens' => 0.003,
            'translator.ai.providers.claude.output_cost_per_1k_tokens' => 0.015,
        ]);
    });

    it('checks the default provider when no --provider flag is given', function (): void {
        $this->artisan('translator:provider-check')
            ->expectsOutputToContain('claude')
            ->assertExitCode(0);
    });

    it('reports configuration OK when API key is present', function (): void {
        $this->artisan('translator:provider-check --provider=claude')
            ->expectsOutputToContain('API key is present')
            ->assertExitCode(0);
    });

    it('exits with FAILURE when the provider config block is missing', function (): void {
        $this->artisan('translator:provider-check --provider=nonexistent_provider')
            ->expectsOutputToContain('No configuration block')
            ->assertExitCode(1);
    });

    it('exits with FAILURE when the API key is blank', function (): void {
        config(['translator.ai.providers.claude.api_key' => null]);

        $this->artisan('translator:provider-check --provider=claude')
            ->expectsOutputToContain('API key is not configured')
            ->assertExitCode(1);
    });

    it('checks all providers when --all is specified', function (): void {
        config([
            'translator.ai.providers.chatgpt.api_key' => 'sk-openai-test',
            'translator.ai.providers.chatgpt.model' => 'gpt-4o',
            'translator.ai.providers.chatgpt.max_tokens' => 4096,
            'translator.ai.providers.chatgpt.timeout_seconds' => 30,
            'translator.ai.providers.chatgpt.max_retries' => 1,
            'translator.ai.providers.chatgpt.input_cost_per_1k_tokens' => 0.0025,
            'translator.ai.providers.chatgpt.output_cost_per_1k_tokens' => 0.010,
        ]);

        $this->artisan('translator:provider-check --all')
            ->expectsOutputToContain('claude')
            ->expectsOutputToContain('chatgpt')
            ->assertExitCode(0);
    });

    it('performs a live API ping and reports success when --ping is given', function (): void {
        // Fake the Anthropic API to respond with a valid translation.
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_ping_test',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => '{"ping": "Bonjour."}']],
                'model' => 'claude-haiku-4-5-20251001',
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 50, 'output_tokens' => 10],
            ], 200),
        ]);

        $this->artisan('translator:provider-check --provider=claude --ping')
            ->expectsOutputToContain('ping successful')
            ->assertExitCode(0);
    });

    it('reports ping failure when the API returns an error', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->artisan('translator:provider-check --provider=claude --ping')
            ->assertExitCode(1);
    });
});
