<?php

declare(strict_types=1);

use Syriable\Translator\Services\Importer\TranslationStringAnalyzer;

describe('TranslationStringAnalyzer', function (): void {

    beforeEach(function (): void {
        $this->analyzer = new TranslationStringAnalyzer;
    });

    // -------------------------------------------------------------------------
    // extractParameters
    // -------------------------------------------------------------------------

    describe('extractParameters()', function (): void {

        it('extracts colon-prefixed parameters', function (): void {
            $result = $this->analyzer->extractParameters('Hello :name, welcome back.');

            expect($result)->toBe([':name']);
        });

        it('extracts brace-wrapped parameters', function (): void {
            $result = $this->analyzer->extractParameters('You have {count} messages.');

            expect($result)->toBe(['{count}']);
        });

        it('extracts both colon and brace parameters', function (): void {
            $result = $this->analyzer->extractParameters('Hello :name, you have {count} items.');

            expect($result)->toContain(':name')
                ->toContain('{count}')
                ->toHaveCount(2);
        });

        it('deduplicates repeated parameters', function (): void {
            $result = $this->analyzer->extractParameters(':name did something and :name did it again.');

            expect($result)->toBe([':name']);
        });

        it('does not extract colons in URLs', function (): void {
            $result = $this->analyzer->extractParameters('Visit https://example.com for more.');

            expect($result)->toBeEmpty();
        });

        it('returns empty array when no parameters present', function (): void {
            expect($this->analyzer->extractParameters('No parameters here.'))->toBeEmpty();
        });
    });

    // -------------------------------------------------------------------------
    // containsHtml
    // -------------------------------------------------------------------------

    describe('containsHtml()', function (): void {

        it('detects opening tags', function (): void {
            expect($this->analyzer->containsHtml('Click <strong>here</strong>.'))->toBeTrue();
        });

        it('detects self-closing tags', function (): void {
            expect($this->analyzer->containsHtml('Line one<br/>Line two.'))->toBeTrue();
        });

        it('detects tags with attributes', function (): void {
            expect($this->analyzer->containsHtml('<a href="/link">Click</a>'))->toBeTrue();
        });

        it('returns false for plain text', function (): void {
            expect($this->analyzer->containsHtml('No HTML here.'))->toBeFalse();
        });

        it('returns false for closing-tag-only strings', function (): void {
            // Closing tags alone should not trigger the HTML flag.
            expect($this->analyzer->containsHtml('</strong>'))->toBeFalse();
        });
    });

    // -------------------------------------------------------------------------
    // isPlural
    // -------------------------------------------------------------------------

    describe('isPlural()', function (): void {

        it('detects unspaced pipe as plural separator', function (): void {
            expect($this->analyzer->isPlural('one apple|many apples'))->toBeTrue();
        });

        it('detects count-based plural syntax', function (): void {
            expect($this->analyzer->isPlural('{1} item|[2,*] items'))->toBeTrue();
        });

        it('returns false when pipe is surrounded by spaces', function (): void {
            // A spaced pipe is intentional literal text, not a plural delimiter.
            expect($this->analyzer->isPlural('left | right'))->toBeFalse();
        });

        it('returns false when no pipe is present', function (): void {
            expect($this->analyzer->isPlural('No plurals here.'))->toBeFalse();
        });
    });
});
