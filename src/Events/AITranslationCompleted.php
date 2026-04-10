<?php

declare(strict_types=1);

namespace Syriable\Translator\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Syriable\Translator\Models\AITranslationLog;

/**
 * Dispatched after every AI translation execution — both synchronous runs and
 * individual queue job completions.
 *
 * Carries the persisted AITranslationLog record so listeners can inspect token
 * usage, cost, success rate, and failure details without additional queries.
 *
 * Dispatched by: AITranslationService::translate()
 * Controlled by: config('translator.events.ai_translation_completed')
 *
 * Intended uses:
 *  - Sending translation completion notifications from a companion UI package.
 *  - Triggering cache invalidation after new translations are persisted.
 *  - Posting webhook callbacks to external systems.
 *  - Recording cost attribution in a billing system.
 *
 * Usage:
 * ```php
 * use Syriable\Translator\Events\AITranslationCompleted;
 *
 * Event::listen(AITranslationCompleted::class, function (AITranslationCompleted $event): void {
 *     logger()->info('AI translation finished', [
 *         'target'     => $event->log->target_language,
 *         'translated' => $event->log->translated_count,
 *         'cost'       => $event->log->formattedActualCost(),
 *     ]);
 * });
 * ```
 *
 * Disable this event in config:
 * ```php
 * 'events' => ['ai_translation_completed' => false],
 * ```
 */
final readonly class AITranslationCompleted
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  AITranslationLog  $log  The persisted log record for the completed AI translation run.
     */
    public function __construct(
        public AITranslationLog $log,
    ) {}
}