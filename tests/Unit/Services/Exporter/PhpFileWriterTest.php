<?php

declare(strict_types=1);

use Syriable\Translator\Services\Exporter\PhpFileWriter;

describe('PhpFileWriter', function (): void {

    beforeEach(function (): void {
        $this->writer = new PhpFileWriter;
        $this->outDir = sys_get_temp_dir().'/translator_writer_test_'.uniqid();
        mkdir($this->outDir, 0755, true);
    });

    afterEach(function (): void {
        array_map(unlink(...), glob($this->outDir.'/**/*.php', GLOB_BRACE) ?: []);
        array_map(unlink(...), glob($this->outDir.'/*.php') ?: []);
        @rmdir($this->outDir.'/en');
        @rmdir($this->outDir);
    });

    it('writes a valid PHP file that can be required back', function (): void {
        $filePath = $this->outDir.'/en/auth.php';
        $this->writer->write($filePath, ['auth.failed' => 'Invalid credentials.']);

        expect(file_exists($filePath))->toBeTrue();

        $loaded = require $filePath;
        expect($loaded)->toBeArray()
            ->and($loaded['auth']['failed'])->toBe('Invalid credentials.');
    });

    it('creates missing parent directories automatically', function (): void {
        $deep = $this->outDir.'/vendor/spatie/en/permissions.php';
        $this->writer->write($deep, ['role.created' => 'Role created.']);

        expect(file_exists($deep))->toBeTrue();
    });

    it('sorts keys alphabetically when sortKeys is true', function (): void {
        $filePath = $this->outDir.'/sorted.php';
        $this->writer->write($filePath, [
            'z_key' => 'Z value',
            'a_key' => 'A value',
            'm_key' => 'M value',
        ]);

        $content = file_get_contents($filePath);

        // 'a_key' should appear before 'z_key' in the file.
        expect(strpos($content, 'a_key'))->toBeLessThan(strpos($content, 'z_key'));
    });

    it('preserves insertion order when sortKeys is false', function (): void {
        $filePath = $this->outDir.'/unsorted.php';
        $this->writer->write($filePath, [
            'z_key' => 'Z value',
            'a_key' => 'A value',
        ], sortKeys: false);

        $content = file_get_contents($filePath);

        expect(strpos($content, 'z_key'))->toBeLessThan(strpos($content, 'a_key'));
    });

    it('unflattens dot-notation keys into nested arrays', function (): void {
        $result = $this->writer->unflatten([
            'auth.failed' => 'Wrong.',
            'auth.throttle' => 'Too many.',
            'passwords.reset' => 'Reset.',
        ]);

        expect($result['auth']['failed'])->toBe('Wrong.')
            ->and($result['auth']['throttle'])->toBe('Too many.')
            ->and($result['passwords']['reset'])->toBe('Reset.');
    });

    it('produces a file starting with the PHP open tag', function (): void {
        $filePath = $this->outDir.'/header.php';
        $this->writer->write($filePath, ['key' => 'value']);

        expect(file_get_contents($filePath))->toStartWith('<?php');
    });
});
