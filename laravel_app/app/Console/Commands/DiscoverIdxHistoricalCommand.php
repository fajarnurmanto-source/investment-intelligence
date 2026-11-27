<?php

namespace App\Console\Commands;

use App\Services\IdxHistoricalCatalog;
use App\Services\IdxHistoricalSnapshotSource;
use App\Services\HistoricalSignalBootstrap;
use Illuminate\Console\Command;

final class DiscoverIdxHistoricalCommand extends Command
{
    protected $signature = 'ii:discover-idx-history {--user=} {--rights-confirmed} {--limit=10} {--offset=0} {--import}';
    protected $description = 'Inspect bounded public pinned historical candidates, optionally import validated data';

    public function handle(IdxHistoricalCatalog $catalog, HistoricalSignalBootstrap $bootstrap): int
    {
        $owner = filter_var($this->option('user'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
        $offset = filter_var($this->option('offset'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!$owner || !$limit || $offset === false || !$this->option('rights-confirmed')) {
            $this->error('II_IDX_CATALOG_POLICY_REQUIRED');
            return self::FAILURE;
        }
        $candidates = array_keys(array_diff_key($catalog->entries(), IdxHistoricalSnapshotSource::BLOBS));
        $batch = array_slice($candidates, $offset, $limit);
        if ($batch === []) {
            $this->error('II_IDX_CATALOG_PAGE_EMPTY');
            return self::FAILURE;
        }
        $accepted = 0;
        $failed = 0;
        foreach ($batch as $ticker) {
            try {
                $result = $bootstrap->run($owner, true, (bool) $this->option('import'), $ticker);
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                if (in_array($result['status'], ['PREVIEW', 'IMPORTED', 'UNCHANGED'], true)) {
                    $accepted++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $code = $e->getMessage();
                $this->error($ticker.':'.(preg_match('/^II_[A-Z0-9_]+$/D', $code) ? $code : 'II_IDX_CATALOG_ACQUISITION_FAILED'));
                if (in_array($code, ['II_HISTORICAL_OWNER_REQUIRED', 'II_HISTORICAL_STOP_AUTOMATION_FIRST', 'II_HISTORICAL_AUTHORIZATION_CHANGED', 'II_IDX_SNAPSHOT_NETWORK_ERROR'], true)) {
                    $this->error('II_IDX_CATALOG_INTERRUPTED');
                    return self::FAILURE;
                }
            }
        }
        $this->line('II_IDX_CATALOG_TOTAL='.count($candidates).'; NEXT_OFFSET='.($offset + count($batch)).'; ACCEPTED='.$accepted.'; FAILED='.$failed);
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
