<?php

namespace App\Console\Commands;

use App\Services\HistoricalSignalBootstrap;
use Illuminate\Console\Command;

final class BootstrapIdxHistoryCommand extends Command
{
    protected $signature = 'ii:bootstrap-idx-history {--user= : Verified local market owner ID} {--rights-confirmed : Confirm personal noncommercial rights under source terms} {--import : Persist the inspected historical series locally}';
    protected $description = 'Preview or import the pinned BBCA historical research snapshot; no scheduler activation';

    public function handle(HistoricalSignalBootstrap $bootstrap): int
    {
        $owner = filter_var($this->option('user'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$owner) {
            $this->error('II_HISTORICAL_OWNER_REQUIRED');
            return self::FAILURE;
        }
        try {
            $result = $bootstrap->run($owner, (bool) $this->option('rights-confirmed'), (bool) $this->option('import'));
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
