<?php

namespace App\Console\Commands;

use App\Services\DirectMarketAcquisition;
use Illuminate\Console\Command;

final class AcquireDirectMarketCommand extends Command
{
    protected $signature = 'ii:acquire-direct-market {--user=} {--symbol=} {--mic=} {--currency=} {--timezone=} {--confirm}';
    protected $description = 'Explicit single-instrument licensed EOD acquisition without discovery';

    public function handle(DirectMarketAcquisition $service): int
    {
        try {
            $result = $service->run((int) $this->option('user'), strtoupper(trim((string) $this->option('symbol'))),
                strtoupper(trim((string) $this->option('mic'))), strtoupper(trim((string) $this->option('currency'))),
                trim((string) $this->option('timezone')), (bool) $this->option('confirm'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $this->info('II_DIRECT_ACQUISITION='.$result['status']);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $code = $e->getMessage();
            $this->error(preg_match('/^II_[A-Z0-9_]+$/D', $code) ? $code : 'II_DIRECT_ACQUISITION_FAILED');
            return self::FAILURE;
        }
    }
}
