<?php

namespace App\Console\Commands;

use App\Jobs\IngestFredSeries;
use App\Services\FredIngestionService;
use Illuminate\Console\Command;

class IngestFredCommand extends Command
{
    protected $signature = 'ii:ingest-fred {series? : Allow-listed FRED series} {--sync : Run now instead of dispatching queue jobs}';
    protected $description = 'Ingest allow-listed real economic data from FRED into the Laravel data pipeline';

    public function handle(FredIngestionService $ingestion): int
    {
        $series = $this->argument('series');
        $ids = $series ? [strtoupper((string) $series)] : array_keys((array) config('ii.fred.series'));
        foreach ($ids as $seriesId) {
            if ($this->option('sync')) {
                $run = $ingestion->ingest($seriesId);
                $this->info($seriesId.': '.$run->status.'; records='.$run->records_written.'; run='.$run->id);
            } else {
                IngestFredSeries::dispatch($seriesId);
                $this->info($seriesId.': queued');
            }
        }
        return self::SUCCESS;
    }
}
