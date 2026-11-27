<?php

namespace App\Console\Commands;

use App\Services\HistoricalSignalBootstrap;
use Illuminate\Console\Command;

final class BootstrapIdxHistoryCommand extends Command
{
    protected $signature = 'ii:bootstrap-idx-history {--user= : Verified local market owner ID} {--rights-confirmed : Confirm personal noncommercial rights under source terms} {--import : Persist the inspected historical series locally} {--ticker=BBCA : One approved IDX ticker or ALL}';
    protected $description = 'Preview or import the pinned IDX historical research snapshots; no scheduler activation';

    public function handle(HistoricalSignalBootstrap $bootstrap): int
    {
        $owner = filter_var($this->option('user'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$owner) {
            $this->error('II_HISTORICAL_OWNER_REQUIRED');
            return self::FAILURE;
        }
        $ticker = strtoupper(trim((string) $this->option('ticker')));
        if ($ticker === 'ALL') {
            if (!$this->option('rights-confirmed')) {
                $this->error('II_HISTORICAL_RIGHTS_CONFIRMATION_REQUIRED');
                return self::FAILURE;
            }
            $failed = 0;
            foreach (array_keys(\App\Services\IdxHistoricalSnapshotSource::BLOBS) as $symbol) {
                try {
                    $result = $bootstrap->run($owner, true, (bool) $this->option('import'), $symbol);
                    $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                } catch (\Throwable $e) {
                    $failed++;
                    $code = $e->getMessage();
                    $this->error($symbol.':'.(preg_match('/^II_[A-Z0-9_]+$/D', $code) ? $code : 'II_HISTORICAL_BOOTSTRAP_FAILED'));
                    if (in_array($code, ['II_HISTORICAL_OWNER_REQUIRED', 'II_HISTORICAL_STOP_AUTOMATION_FIRST', 'II_HISTORICAL_AUTHORIZATION_CHANGED'], true)) {
                        return self::FAILURE;
                    }
                }
            }
            $this->info('II_HISTORICAL_BATCH='.($failed === 0 ? 'COMPLETE' : 'PARTIAL').'; FAILED='.$failed);
            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }
        try {
            $result = $bootstrap->run($owner, (bool) $this->option('rights-confirmed'), (bool) $this->option('import'), $ticker);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $this->info('II_HISTORICAL_BOOTSTRAP='.$result['status']);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $code = $e->getMessage();
            $this->error(preg_match('/^II_[A-Z0-9_]+$/D', $code) ? $code : 'II_HISTORICAL_BOOTSTRAP_FAILED');
            return self::FAILURE;
        }
    }
}
