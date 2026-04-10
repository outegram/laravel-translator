<?php

declare(strict_types=1);

namespace Syriable\Translator\Facades;

use Illuminate\Support\Facades\Facade;
use Syriable\Translator\Services\AI\AITranslationService;

/**
 * @method static \Syriable\Translator\DTOs\AI\TranslationEstimate estimate(\Syriable\Translator\DTOs\AI\TranslationRequest $request, ?string $provider = null)
 * @method static \Syriable\Translator\DTOs\AI\TranslationResponse translate(\Syriable\Translator\DTOs\AI\TranslationRequest $request, \Syriable\Translator\Models\Language $language, ?string $provider = null, ?\Syriable\Translator\DTOs\AI\TranslationEstimate $estimate = null)
 *
 * @see AITranslationService
 */
final class Translator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AITranslationService::class;
    }
}
