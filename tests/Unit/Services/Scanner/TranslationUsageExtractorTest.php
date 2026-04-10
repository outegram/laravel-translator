<?php

declare(strict_types=1);

use Syriable\Translator\DTOs\ScannedFile;
use Syriable\Translator\Services\Scanner\TranslationUsageExtractor;

describe('TranslationUsageExtractor', function (): void {

    beforeEach(function (): void {
        $this->extractor = new TranslationUsageExtractor;
    });

    // -------------------------------------------------------------------------
    // extractFromContent() — PHP patterns
    // -------------------------------------------------------------------------

    describe('PHP helper functions', function (): void {

        it('extracts __() with single quotes', function (): void {
            $keys = $this->extractor->extractFromContent("<?php echo __('auth.failed');", 'php');
            expect($keys)->toContain('auth.failed');
        });

        it('extracts __() with double quotes', function (): void {
            $keys = $this->extractor->extractFromContent('<?php echo __("auth.failed");', 'php');
            expect($keys)->toContain('auth.failed');
        });

        it('extracts __() with surrounding whitespace', function (): void {
            $keys = $this->extractor->extractFromContent("<?php echo __( 'auth.failed' );", 'php');
            expect($keys)->toContain('auth.failed');
        });

        it('extracts trans() helper', function (): void {
            $keys = $this->extractor->extractFromContent("<?php return trans('messages.welcome');", 'php');
            expect($keys)->toContain('messages.welcome');
        });

        it('extracts trans_choice() helper', function (): void {
            $keys = $this->extractor->extractFromContent(
                "<?php return trans_choice('messages.count', \$n);",
                'php',
            );
            expect($keys)->toContain('messages.count');
        });
    });

    // -------------------------------------------------------------------------
    // extractFromContent() — Blade patterns
    // -------------------------------------------------------------------------

    describe('Blade directives and helpers', function (): void {

        it('extracts @lang directive', function (): void {
            $keys = $this->extractor->extractFromContent(
                '<p>@lang(\'auth.throttle\')</p>',
                'blade.php',
            );
            expect($keys)->toContain('auth.throttle');
        });

        it('extracts __() inside {{ }} in Blade', function (): void {
            $keys = $this->extractor->extractFromContent(
                '{{ __(\'validation.required\') }}',
                'blade.php',
            );
            expect($keys)->toContain('validation.required');
        });

        it('extracts Lang::get() facade call', function (): void {
            $keys = $this->extractor->extractFromContent(
                "<?php Lang::get('auth.failed');",
                'php',
            );
            expect($keys)->toContain('auth.failed');
        });

        it('extracts Lang::choice() facade call', function (): void {
            $keys = $this->extractor->extractFromContent(
                "<?php Lang::choice('items.count', 3);",
                'php',
            );
            expect($keys)->toContain('items.count');
        });
    });

    // -------------------------------------------------------------------------
    // extractFromContent() — JSON (no dot) keys
    // -------------------------------------------------------------------------

    describe('JSON-style keys', function (): void {

        it('extracts plain string keys with spaces', function (): void {
            $keys = $this->extractor->extractFromContent(
                "<?php echo __('Welcome to our app');",
                'php',
            );
            expect($keys)->toContain('Welcome to our app');
        });
    });

    // -------------------------------------------------------------------------
    // extractFromContent() — JavaScript / Vue
    // -------------------------------------------------------------------------

    describe('JavaScript and Vue patterns', function (): void {

        it('extracts __() in JS files', function (): void {
            $keys = $this->extractor->extractFromContent(
                "const msg = __('auth.failed');",
                'js',
            );
            expect($keys)->toContain('auth.failed');
        });

        it('extracts $t() in Vue files', function (): void {
            $keys = $this->extractor->extractFromContent(
                "<template><p>{{ \$t('auth.failed') }}</p></template>",
                'vue',
            );
            expect($keys)->toContain('auth.failed');
        });

        it('extracts i18n.t() in TypeScript files', function (): void {
            $keys = $this->extractor->extractFromContent(
                "const msg = i18n.t('auth.failed');",
                'ts',
            );
            expect($keys)->toContain('auth.failed');
        });

        it('extracts __() with backtick strings in JS', function (): void {
            $keys = $this->extractor->extractFromContent(
                'const msg = __(`auth.failed`);',
                'js',
            );
            expect($keys)->toContain('auth.failed');
        });
    });

    // -------------------------------------------------------------------------
    // Deduplication
    // -------------------------------------------------------------------------

    it('deduplicates repeated keys within the same file', function (): void {
        $content = "<?php\n__('auth.failed');\n__('auth.failed');\n__('auth.throttle');";
        $keys = $this->extractor->extractFromContent($content, 'php');

        expect($keys)->toHaveCount(2)
            ->toContain('auth.failed')
            ->toContain('auth.throttle');
    });

    it('returns keys sorted alphabetically', function (): void {
        $content = "<?php __('z.key'); __('a.key'); __('m.key');";
        $keys = $this->extractor->extractFromContent($content, 'php');

        expect($keys)->toBe(['a.key', 'm.key', 'z.key']);
    });

    // -------------------------------------------------------------------------
    // Rejection of invalid/dynamic keys
    // -------------------------------------------------------------------------

    it('ignores keys containing PHP variable interpolation', function (): void {
        $keys = $this->extractor->extractFromContent(
            '<?php __("auth.$action");',
            'php',
        );
        expect($keys)->toBeEmpty();
    });

    it('ignores keys with $ sigils', function (): void {
        $keys = $this->extractor->extractFromContent(
            "<?php __('prefix.\$variable');",
            'php',
        );
        expect($keys)->toBeEmpty();
    });

    it('ignores keys longer than 255 characters', function (): void {
        $longKey = str_repeat('a', 256);
        $keys = $this->extractor->extractFromContent(
            "<?php __('{$longKey}');",
            'php',
        );
        expect($keys)->toBeEmpty();
    });

    it('returns empty array for unsupported file extensions', function (): void {
        $keys = $this->extractor->extractFromContent("__('auth.failed')", 'css');
        expect($keys)->toBeEmpty();
    });

    it('returns empty array for empty content', function (): void {
        $keys = $this->extractor->extractFromContent('', 'php');
        expect($keys)->toBeEmpty();
    });

    // -------------------------------------------------------------------------
    // extractFromFile()
    // -------------------------------------------------------------------------

    it('reads and extracts keys from a real file on disk', function (): void {
        $tempFile = sys_get_temp_dir().'/extractor_test_'.uniqid().'.php';
        file_put_contents($tempFile, "<?php echo __('auth.failed'); echo trans('auth.throttle');");

        $file = new ScannedFile(absolutePath: $tempFile, relativePath: 'auth.php');
        $keys = $this->extractor->extractFromFile($file);

        unlink($tempFile);

        expect($keys)->toContain('auth.failed')
            ->toContain('auth.throttle');
    });

    it('returns empty array when file does not exist', function (): void {
        $file = new ScannedFile(
            absolutePath: '/nonexistent/path/file.php',
            relativePath: 'file.php',
        );
        expect($this->extractor->extractFromFile($file))->toBeEmpty();
    });

    it('handles compound blade.php extension correctly', function (): void {
        $tempFile = sys_get_temp_dir().'/extractor_test_'.uniqid().'.blade.php';
        file_put_contents($tempFile, "@lang('auth.failed')");

        $file = new ScannedFile(absolutePath: $tempFile, relativePath: 'view.blade.php');
        $keys = $this->extractor->extractFromFile($file);

        unlink($tempFile);

        expect($keys)->toContain('auth.failed');
    });
});
