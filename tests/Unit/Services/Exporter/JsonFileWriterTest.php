<?php

declare(strict_types=1);

use Syriable\Translator\Services\Exporter\JsonFileWriter;

describe('JsonFileWriter', function (): void {

    beforeEach(function (): void {
        $this->writer = new JsonFileWriter;
        $this->outDir = sys_get_temp_dir().'/translator_json_writer_'.uniqid();
        mkdir($this->outDir, 0755, true);
    });

    afterEach(function (): void {
        if (! is_dir($this->outDir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->outDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($this->outDir);
    });

    it('writes valid JSON that can be decoded back', function (): void {
        $filePath = $this->outDir.'/en.json';
        $this->writer->write($filePath, ['Welcome' => 'Welcome', 'Goodbye' => 'Goodbye']);

        $decoded = json_decode(file_get_contents($filePath), associative: true);

        expect($decoded)->toHaveKey('Welcome', 'Welcome')
            ->toHaveKey('Goodbye', 'Goodbye');
    });

    it('preserves UTF-8 Unicode characters without escaping', function (): void {
        $filePath = $this->outDir.'/ar.json';
        $this->writer->write($filePath, ['مرحبا' => 'مرحبا بالعالم']);

        $content = file_get_contents($filePath);

        // Unescaped Unicode: Arabic characters must appear as-is.
        expect($content)->toContain('مرحبا بالعالم');
        expect($content)->not->toContain('\u');
    });

    it('sorts keys alphabetically when sortKeys is true', function (): void {
        $filePath = $this->outDir.'/sorted.json';
        $this->writer->write($filePath, ['z' => 'Z', 'a' => 'A', 'm' => 'M']);

        $decoded = json_decode(file_get_contents($filePath), associative: true);
        $keys = array_keys($decoded);

        expect($keys)->toBe(['a', 'm', 'z']);
    });

    it('writes pretty-printed JSON by default', function (): void {
        $filePath = $this->outDir.'/pretty.json';
        $this->writer->write($filePath, ['key' => 'value']);

        $content = file_get_contents($filePath);

        // Pretty-printed JSON contains newlines and spaces.
        expect($content)->toContain("\n")
            ->toContain('    ');
    });

    it('ends the file with a newline for POSIX compliance', function (): void {
        $filePath = $this->outDir.'/newline.json';
        $this->writer->write($filePath, ['key' => 'value']);

        expect(file_get_contents($filePath))->toEndWith("\n");
    });

    it('creates missing parent directories', function (): void {
        $deep = $this->outDir.'/nested/deep/ar.json';
        $this->writer->write($deep, ['key' => 'value']);

        expect(file_exists($deep))->toBeTrue();
    });
});
