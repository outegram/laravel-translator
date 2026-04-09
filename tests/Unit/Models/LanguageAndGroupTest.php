<?php

declare(strict_types=1);

use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;

describe('Language model', function (): void {

    it('scopeActive returns only active languages', function (): void {
        Language::factory()->count(2)->create(['active' => true]);
        Language::factory()->inactive()->create();

        expect(Language::query()->active()->count())->toBe(2);
    });

    it('scopeSource returns the source language only', function (): void {
        Language::factory()->english()->create();
        Language::factory()->french()->create();

        expect(Language::query()->source()->count())->toBe(1)
            ->and(Language::query()->source()->first()->code)->toBe('en');
    });

    it('scopeRtl returns only right-to-left languages', function (): void {
        Language::factory()->arabic()->create();
        Language::factory()->french()->create();

        expect(Language::query()->rtl()->count())->toBe(1)
            ->and(Language::query()->rtl()->first()->code)->toBe('ar');
    });

    it('isRtl() returns true for RTL languages', function (): void {
        $ar = Language::factory()->arabic()->create();
        $fr = Language::factory()->french()->create();

        expect($ar->isRtl())->toBeTrue()
            ->and($fr->isRtl())->toBeFalse();
    });

    it('isSource() returns true for the source language', function (): void {
        $en = Language::factory()->english()->create();
        $fr = Language::factory()->french()->create();

        expect($en->isSource())->toBeTrue()
            ->and($fr->isSource())->toBeFalse();
    });
});

describe('Group model', function (): void {

    it('scopeApplication returns groups without a namespace', function (): void {
        Group::factory()->create(['namespace' => null]);
        Group::factory()->vendor()->create();

        expect(Group::query()->application()->count())->toBe(1);
    });

    it('scopeVendor returns groups with a namespace', function (): void {
        Group::factory()->create(['namespace' => null]);
        Group::factory()->vendor('spatie')->create();

        expect(Group::query()->vendor()->count())->toBe(1);
    });

    it('isVendor() returns true when namespace is set', function (): void {
        $app = Group::factory()->create(['namespace' => null]);
        $vendor = Group::factory()->vendor()->create();

        expect($app->isVendor())->toBeFalse()
            ->and($vendor->isVendor())->toBeTrue();
    });

    it('isJson() returns true for JSON groups', function (): void {
        $php = Group::factory()->create(['file_format' => 'php']);
        $json = Group::factory()->json()->create();

        expect($php->isJson())->toBeFalse()
            ->and($json->isJson())->toBeTrue();
    });

    it('qualifiedName() returns name for application groups', function (): void {
        $group = Group::factory()->auth()->create();

        expect($group->qualifiedName())->toBe('auth');
    });

    it('qualifiedName() returns namespace::name for vendor groups', function (): void {
        $group = Group::factory()->vendor('spatie')->create(['name' => 'permissions']);

        expect($group->qualifiedName())->toBe('spatie::permissions');
    });

    it('scopeForNamespace filters by namespace correctly', function (): void {
        Group::factory()->vendor('spatie')->create(['name' => 'permissions']);
        Group::factory()->vendor('nova')->create(['name' => 'auth']);
        Group::factory()->create(['namespace' => null]);

        expect(Group::query()->forNamespace('spatie')->count())->toBe(1);
    });

    it('scopeWithFormat filters by file format', function (): void {
        Group::factory()->create(['file_format' => 'php']);
        Group::factory()->json()->create();

        expect(Group::query()->withFormat('json')->count())->toBe(1)
            ->and(Group::query()->withFormat('php')->count())->toBe(1);
    });
});
