<?php

declare(strict_types=1);

namespace Syriable\Translator\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Syriable\Translator\Models\ImportLog;

/**
 * Dispatched at the end of every successful translation import run.
 *
 * Carries the persisted ImportLog record so listeners can inspect
 * counters, duration, and trigger metadata without additional queries.
 *
 * Dispatched by: TranslationImporter::import()
 * Controlled by: config('translator.events.import_completed')
 *
 * Usage:
 * ```php
 * Event::listen(ImportCompleted::class, function (ImportCompleted $event) {
 *     logger()->info('Import finished', ['log' => $event->log->id]);
 * });
 * ```
 */
final readonly class ImportCompleted
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  ImportLog  $log  The persisted log record for the completed import run.
     */
    public function __construct(
        public ImportLog $log,
    ) {}
}
