<?php

namespace App\Console\Commands;

use App\Services\IdxApiFreeFundamentalsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Explicit/opt-in weekly free-tier financial statement acquisition.
 * Snapshots are private source-review bundles, not admitted issuer metrics.
 */
class IIIdxApiFreeSync extends Command
{
    protected $signature = 'ii:idxapi:sync {--ticker= : Fetch one specific BEI ticker} {--year= : FY reporting year, default prior year}';

    protected $description = 'Fetch up to 10 IDXAPI Free-tier issuer FY bundles into private source-review storage';

    public function handle(IdxApiFreeFundamentalsService $api): int
    {
        if (trim((string) config('ii.idxapi.key', '')) === '') {
            $this->error('II_IDXAPI_KEY_MISSING=YES');

            return self::FAILURE;
        }

        $one = strtoupper(trim((string) $this->option('ticker')));
        $tickers = $one !== '' ? [$one] : explode(',', (string) config('ii.idxapi.tickers', ''));
        $tickers = array_values(array_unique(array_filter(array_map(
            fn ($t) => strtoupper(trim((string) $t)),
            $tickers
        ))));
        $max = min(10, max(1, (int) config('ii.idxapi.max_tickers_per_run', 10)));
        if ($tickers === [] || count($tickers) > $max) {
            $this->error('II_IDXAPI_WATCHLIST_INVALID=YES;MAX='.$max);

            return self::FAILURE;
        }
        foreach ($tickers as $ticker) {
            if (! preg_match('/^[A-Z0-9]{4,6}$/D', $ticker) || str_starts_with($ticker, 'DEMO')) {
                $this->error('II_IDXAPI_TICKER_INVALID=YES');

                return self::FAILURE;
            }
        }

        $year = $this->option('year') !== null ? (string) $this->option('year') : (string) ((int) date('Y') - 1);
        if (! preg_match('/^20\d{2}$/D', $year) || (int) $year > (int) date('Y')) {
            $this->error('II_IDXAPI_YEAR_INVALID=YES');

            return self::FAILURE;
        }

        $failed = 0;
        $saved = 0;
        foreach ($tickers as $ticker) {
            try {
                $snapshot = $api->fetchAnnual($ticker, (int) $year);
                $hashes = array_map(fn (array $item) => $item['source_sha256'], $snapshot['statements']);
                $sourceHash = hash('sha256', implode('|', $hashes));
                $path = 'ii_idxapi_review/FY'.$year.'/'.$ticker.'-'.$sourceHash.'.json';
                if (Storage::disk('local')->exists($path)) {
                    $this->line('II_IDXAPI_ALREADY_ARCHIVED='.$ticker);

                    continue;
                }
                $payload = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if (! Storage::disk('local')->put($path, $payload)) {
                    throw new \RuntimeException('Snapshot cannot be saved to private research disk.');
                }
                $saved++;
                $this->line('II_IDXAPI_SOURCE_REVIEW_SAVED='.$ticker.';SHA256='.$sourceHash);
            } catch (\Throwable $e) {
                $failed++;
                // Avoid logging bearer tokens, request headers or raw exception.
                $this->warn('II_IDXAPI_SOURCE_REVIEW_FAILED='.$ticker.';CLASS='.class_basename($e));
            }
        }

        $this->line('II_IDXAPI_API_REQUEST_BUDGET_MAX='.($max * 3));
        $this->line('II_IDXAPI_REVIEW_FILES_SAVED='.$saved);
        $this->line('II_IDXAPI_AUTOMATIC_DB_IMPORT=NO');
        $this->line('II_IDXAPI_RESULT='.($failed === 0 ? 'PASS' : 'ATTENTION_REQUIRED'));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
