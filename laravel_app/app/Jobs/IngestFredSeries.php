<?php

namespace App\Jobs;

use App\Services\FredIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestFredSeries implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 90;

    public function __construct(public readonly string $seriesId) {}

    public function handle(FredIngestionService $ingestion): void
    {
        $ingestion->ingest($this->seriesId);
    }
}
