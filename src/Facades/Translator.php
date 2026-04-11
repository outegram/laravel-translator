<?php

declare(strict_types=1);

namespace Syriable\Translator\Facades;

use Illuminate\Support\Facades\Facade;
use Syriable\Translator\Contracts\AITranslationServiceContract;
use Syriable\Translator\DTOs\AI\TranslationEstimate;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\DTOs\AI\TranslationResponse;
use Syriable\Translator\Models\Language;

/**
 * Facade providing static access to the AI translation service.
 *
 * Resolves {@see AITranslationServiceContract} from the container (not Laravel's
 * `translator` binding, which is {@see \Illuminate\Contracts\Translation\Translator}).
 *
 * Usage:
 * ```php
 * use Syriable\Translator\Facades\Translator;
 *
 * // Estimate cost before committing
 * $estimate = Translator::estimate($request);
 *
 * // Execute translation after user confirms
 * $response = Translator::translate($request, $language);
 * ```
 *
 * @method static TranslationEstimate estimate(TranslationRequest $request, ?string $provider = null)
 * @method static TranslationResponse translate(TranslationRequest $request, Language $language, ?string $provider = null, ?TranslationEstimate $estimate = null)
 *
 * @see \Syriable\Translator\Services\AI\AITranslationService
 * @see AITranslationServiceContract
 */
final class Translator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AITranslationServiceContract::class;
    }
}
