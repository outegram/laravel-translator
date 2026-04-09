<?php

declare(strict_types=1);

use Syriable\Translator\DTOs\ScannedFile;
use Syriable\Translator\Services\Scanner\FileWalker;

describe('FileWalker', function (): void {

    beforeEach(function (): void {
        $this->walker = new FileWalker;
        $this->baseDir = sys_get_temp_dir().'/translator_walker_'.uniqid();

        // Build a representative directory tree:
        // base/
        //   app/
        //     Http/Controllers/HomeController.php
        //     Models/User.php
        //   resources/views/
        //     welcome.blade.php
        //   vendor/
        //     package/SomeClass.php   ← should be ignored
        //   node_modules/
        //     lib/index.js            ← should be ignored

        $dirs = [
            $this->baseDir.'/app/Http/Controllers',
            $this->baseDir.'/app/Models',
            $this->baseDir.'/resources/views',
            $this->baseDir.'/vendor/package',
            $this->baseDir.'/node_modules/lib',
        ];

        foreach ($dirs as $dir) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->baseDir.'/app/Http/Controllers/HomeController.php', '<?php');
        file_put_contents($this->baseDir.'/app/Models/User.php', '<?php');
        file_put_contents($this->baseDir.'/resources/views/welcome.blade.php', '<html>');
        file_put_contents($this->baseDir.'/vendor/package/SomeClass.php', '<?php');
        file_put_contents($this->baseDir.'/node_modules/lib/index.js', 'module.exports = {}');
    });

    afterEach(function (): void {
        // Recursively remove temp directory.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->baseDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->baseDir);
    });

    it('yields ScannedFile instances for each qualifying file', function (): void {
        $files = iterator_to_array($this->walker->walk(
            directories: [$this->baseDir.'/app'],
            ignoredSegments: [],
            allowedExtensions: ['php'],
        ));

        expect($files)->each->toBeInstanceOf(ScannedFile::class);
    });

    it('discovers PHP files in nested directories', function (): void {
        $files = iterator_to_array($this->walker->walk(
            directories: [$this->baseDir.'/app'],
            ignoredSegments: [],
            allowedExtensions: ['php'],
        ));

        $paths = array_map(static fn (ScannedFile $f) => basename($f->absolutePath), $files);

        expect($paths)->toContain('HomeController.php')
            ->toContain('User.php');
    });

    it('skips directories matching ignored segments', function (): void {
        $files = iterator_to_array($this->walker->walk(
            directories: [$this->baseDir],
            ignoredSegments: ['vendor', 'node_modules'],
            allowedExtensions: [],
        ));

        $paths = array_map(static fn (ScannedFile $f) => $f->absolutePath, $files);

        foreach ($paths as $path) {
            expect($path)->not->toContain('/vendor/')
                ->not->toContain('/node_modules/');
        }
    });

    it('filters by extension when allowedExtensions is non-empty', function (): void {
        $files = iterator_to_array($this->walker->walk(
            directories: [$this->baseDir],
            ignoredSegments: ['vendor', 'node_modules'],
            allowedExtensions: ['php'],
        ));

        $paths = array_map(static fn (ScannedFile $f) => $f->absolutePath, $files);

        foreach ($paths as $path) {
            expect($path)->toEndWith('.php');
        }
    });

    it('yields all file types when allowedExtensions is empty', function (): void {
        $files = iterator_to_array($this->walker->walk(
            directories: [$this->baseDir.'/resources'],
            ignoredSegments: [],
            allowedExtensions: [],
        ));

        expect(count($files))->toBeGreaterThan(0);
    });

    it('skips non-existent directories gracefully', function (): void {
        $files = iterator_to_array($this->walker->walk(
            directories: ['/nonexistent/path/that/does/not/exist'],
            ignoredSegments: [],
            allowedExtensions: [],
        ));

        expect($files)->toBeEmpty();
    });

    it('returns an absolute path and a relative path on each ScannedFile', function (): void {
        $files = iterator_to_array($this->walker->walk(
            directories: [$this->baseDir.'/app'],
            ignoredSegments: [],
            allowedExtensions: ['php'],
        ));

        expect($files)->not->toBeEmpty();

        $first = $files[0];
        expect($first->absolutePath)->toBeString()->not->toBeEmpty()
            ->and($first->relativePath)->toBeString()->not->toBeEmpty();
    });

    it('handles multiple scan paths in one call', function (): void {
        $files = iterator_to_array($this->walker->walk(
            directories: [
                $this->baseDir.'/app',
                $this->baseDir.'/resources',
            ],
            ignoredSegments: [],
            allowedExtensions: ['php', 'blade.php'],
        ));

        $paths = array_map(static fn (ScannedFile $f) => basename($f->absolutePath), $files);

        expect($paths)->toContain('HomeController.php')
            ->toContain('welcome.blade.php');
    });
});
