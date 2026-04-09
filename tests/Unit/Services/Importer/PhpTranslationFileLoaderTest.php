<?php

declare(strict_types=1);

use Syriable\Translator\Services\Importer\PhpTranslationFileLoader;

describe('PhpTranslationFileLoader', function (): void {

    beforeEach(function (): void {
        $this->loader = new PhpTranslationFileLoader;
        $this->langDir = sys_get_temp_dir().'/translator_test_'.uniqid();
        mkdir($this->langDir, 0755, true);
    });

    afterEach(function (): void {
        // Clean up temp files.
        array_map(unlink(...), glob($this->langDir.'/*') ?: []);
        rmdir($this->langDir);
    });

    it('loads a valid PHP translation file and returns dot-notation array', function (): void {
        $file = $this->langDir.'/auth.php';
        file_put_contents($file, "<?php\nreturn ['failed' => 'Wrong credentials.', 'nested' => ['key' => 'value']];");

        $result = $this->loader->load($file, $this->langDir);

        expect($result)->toHaveKey('failed', 'Wrong credentials.')
            ->toHaveKey('nested.key', 'value');
    });

    it('returns empty array when file does not exist', function (): void {
        expect($this->loader->load('/nonexistent/path/file.php', $this->langDir))->toBeEmpty();
    });

    it('returns empty array when file is not a PHP file', function (): void {
        $file = $this->langDir.'/translations.json';
        file_put_contents($file, '{"key": "value"}');

        expect($this->loader->load($file, $this->langDir))->toBeEmpty();
    });

    it('returns empty array when file does not return an array', function (): void {
        $file = $this->langDir.'/invalid.php';
        file_put_contents($file, "<?php\nreturn 'not an array';");

        expect($this->loader->load($file, $this->langDir))->toBeEmpty();
    });

    it('refuses to load files outside the permitted base path', function (): void {
        // Create a file outside the declared base directory.
        $outsideFile = sys_get_temp_dir().'/outside_'.uniqid().'.php';
        file_put_contents($outsideFile, "<?php\nreturn ['key' => 'value'];");

        $result = $this->loader->load($outsideFile, $this->langDir);

        expect($result)->toBeEmpty();

        unlink($outsideFile);
    });
});
