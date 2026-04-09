<?php

declare(strict_types=1);

namespace Syriable\Translator\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Syriable\Translator\Models\ExportLog;

/**
 * Dispatched at the end of every successful translation export run.
 *
 * Carries the persisted ExportLog record so listeners can inspect
 * counters and duration without additional queries.
 *
 * Dispatched by: TranslationExporter::export()
 * Controlled by: config('translator.events.export_completed')
 */
final readonly class ExportCompleted
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  ExportLog  $log  The persisted log record for the completed export run.
     */
    public function __construct(
        public ExportLog $log,
    ) {}
}
