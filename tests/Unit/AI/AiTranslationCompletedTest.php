<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Syriable\Translator\AI\Contracts\TranslationProviderInterface;
use Syriable\Translator\AI\TranslationProviderManager;
use Syriable\Translator\DTOs\AI\TranslationEstimate;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\DTOs\AI\TranslationResponse;
use Syriable\Translator\Events\AITranslationCompleted;
use Syriable\Translator\Models\AITranslationLog;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;
use Syriable\Translator\Services\AI\AITranslationService;

function makeEventStubProvider(array $translations = []): TranslationProviderInterface
{
    return new readonly class($translations) implements TranslationProviderInterface
    {
        public function __construct(private array $translations) {}

        public function estimate(TranslationRequest $r): TranslationEstimate
        {
            return new TranslationEstimate('stub', 'stub-1', 100, 50, 0.001, $r->keyCount(), 10);
        }

        public function translate(TranslationRequest $r): TranslationResponse
        {
            return new TranslationResponse('stub', 'stub-1', $this->translations, [], 100, 50, 0.001, 42);
        }

        public function providerName(): string { return 'stub'; }

        public function isAvailable(): bool { return true; }
    };
}

describe('AITranslationCompleted event', function (): void {

    beforeEach(function (): void {
        config([
            'translator.ai.cache.enabled' => false,
            'translator.events.ai_translation_completed' => true,
        ]);

        $this->targetLanguage = Language::factory()->french()->create();
        $group = Group::factory()->auth()->create();
        $key = TranslationKey::factory()->create(['group_id' => $group->id, 'key' => 'failed']);
        Translation::factory()->create([
            'translation_key_id' => $key->id,
            'language_id' => $this->targetLanguage->id,
        ]);

        $stub = makeEventStubProvider(['failed' => 'Identifiants incorrects.']);
        $manager = Mockery::mock(TranslationProviderManager::class);
        $manager->shouldReceive('driver')->andReturn($stub);
        $this->app->instance(TranslationProviderManager::class, $manager);

        $this->service = app(AITranslationService::class);
    });

    it('dispatches AITranslationCompleted after a successful API translation', function (): void {
        Event::fake([AITranslationCompleted::class]);

        $request = new TranslationRequest('en', 'fr', ['failed' => 'Wrong credentials.'], 'auth');
        $this->service->translate($request, $this->targetLanguage, 'stub');

        Event::assertDispatched(AITranslationCompleted::class);
    });

    it('dispatches AITranslationCompleted with the correct log record', function (): void {
        Event::fake([AITranslationCompleted::class]);

        $request = new TranslationRequest('en', 'fr', ['failed' => 'Wrong credentials.'], 'auth');
        $this->service->translate($request, $this->targetLanguage, 'stub');

        Event::assertDispatched(
            AITranslationCompleted::class,
            static function (AITranslationCompleted $event): bool {
                return $event->log instanceof AITranslationLog
                    && $event->log->target_language === 'fr'
                    && $event->log->translated_count === 1;
            },
        );
    });

    it('dispatches AITranslationCompleted even on a full cache hit', function (): void {
        config(['translator.ai.cache.enabled' => true]);

        // Pre-warm the cache.
        $prefix = config('translator.ai.cache.prefix', 'translator_ai');
        cache()->put("{$prefix}:fr:failed:".md5('Wrong credentials.'), 'Identifiants incorrects.', 3600);

        Event::fake([AITranslationCompleted::class]);

        $request = new TranslationRequest('en', 'fr', ['failed' => 'Wrong credentials.'], 'auth');
        $this->service->translate($request, $this->targetLanguage, 'stub');

        Event::assertDispatched(AITranslationCompleted::class);

        cache()->flush();
    });

    it('does not dispatch AITranslationCompleted when the event is disabled in config', function (): void {
        config(['translator.events.ai_translation_completed' => false]);

        Event::fake([AITranslationCompleted::class]);

        $request = new TranslationRequest('en', 'fr', ['failed' => 'Wrong credentials.'], 'auth');
        $this->service->translate($request, $this->targetLanguage, 'stub');

        Event::assertNotDispatched(AITranslationCompleted::class);
    });
});