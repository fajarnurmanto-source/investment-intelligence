<?php

namespace App\Console\Commands;

use App\Services\HistoricalAcquisitionCycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class HistoricalAcquisitionCycleCommand extends Command
{
    protected $signature = 'ii:historical-cycle {action : enable|stop|status|tick|scheduled} {--user=} {--rights-confirmed}';
    protected $description = 'Manage opt-in zero-subscription pinned historical acquisition';

    public function handle(HistoricalAcquisitionCycle $cycle): int
    {
        $action = $this->argument('action');
        try {
            if ($action === 'scheduled') {
                foreach (DB::table('ii_historical_cycles')->where('enabled', true)->pluck('user_id') as $owner) {
                    $this->line(json_encode($cycle->tick((int) $owner), JSON_THROW_ON_ERROR));
                }
                return self::SUCCESS;
            }
            $owner = filter_var($this->option('user'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$owner) {
                throw new \InvalidArgumentException('II_CYCLE_OWNER_REQUIRED');
            }
            if ($action === 'enable') {
                $cycle->enable($owner, (bool) $this->option('rights-confirmed'));
            } elseif ($action === 'stop') {
                $cycle->stop($owner);
            } elseif (!in_array($action, ['tick','status'], true)) {
                throw new \InvalidArgumentException('II_CYCLE_ACTION_INVALID');
            }
            $result = $action === 'tick' ? $cycle->tick($owner) : $cycle->status($owner);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            return in_array($result['state'] ?? '', ['REVIEW_REQUIRED','POLICY_PAUSED'], true) ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $code = $e->getMessage();
            $this->error(preg_match('/^II_[A-Z0-9_]+$/D', $code) ? $code : 'II_CYCLE_EXECUTION_FAILED');
            return self::FAILURE;
        }
    }
}
