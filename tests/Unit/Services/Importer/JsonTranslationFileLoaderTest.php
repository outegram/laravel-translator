<?php

declare(strict_types=1);

use Syriable\Translator\Services\Importer\JsonTranslationFileLoader;

describe('JsonTranslationFileLoader', function (): void {

    beforeEach(function (): void {
        $this->loader = new JsonTranslationFileLoader;
        $this->langDir = sys_get_temp_dir().'/translator_json_test_'.uniqid();
        mkdir($this->langDir, 0755, true);
    });

    afterEach(function (): void {
        array_map(unlink(...), glob($this->langDir.'/*') ?: []);
        rmdir($this->langDir);
    });

    it('loads a valid JSON translation file', function (): void {
        $file = $this->langDir.'/en.json';
        file_put_contents($file, json_encode(['Welcome' => 'Welcome', 'Goodbye' => 'Goodbye']));

        $result = $this->loader->load($file);

        expect($result)->toHaveKey('Welcome', 'Welcome')
            ->toHaveKey('Goodbye', 'Goodbye');
    });

    it('returns empty array for malformed JSON', function (): void {
        $file = $this->langDir.'/broken.json';
        file_put_contents($file, '{invalid json}');

        expect($this->loader->load($file))->toBeEmpty();
    });

    it('returns empty array when file does not exist', function (): void {
        expect($this->loader->load('/nonexistent/file.json'))->toBeEmpty();
    });

    it('discovers JSON locale files in the lang directory', function (): void {
        file_put_contents($this->langDir.'/en.json', '{}');
        file_put_contents($this->langDir.'/ar.json', '{}');
        file_put_contents($this->langDir.'/fr.json', '{}');
        // Should NOT be included (not a JSON file).
        file_put_contents($this->langDir.'/other.php', '<?php return [];');

        $files = $this->loader->discoverLocaleFiles($this->langDir);

        expect($files)->toHaveKeys(['ar', 'en', 'fr'])
            ->not->toHaveKey('other');
    });

    it('returns locale files sorted alphabetically', function (): void {
        file_put_contents($this->langDir.'/zh.json', '{}');
        file_put_contents($this->langDir.'/ar.json', '{}');
        file_put_contents($this->langDir.'/en.json', '{}');

        $keys = array_keys($this->loader->discoverLocaleFiles($this->langDir));

        expect($keys)->toBe(['ar', 'en', 'zh']);
    });

    it('returns empty array when lang path does not exist', function (): void {
        expect($this->loader->discoverLocaleFiles('/nonexistent/path'))->toBeEmpty();
    });
});
