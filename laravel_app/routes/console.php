<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ii:ingest-fred')
    ->dailyAt('06:15')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();


// Provider BYOK acquisition is user-initiated, on-device, and never scheduled.

// Only an independently licensed private local feed; never the restricted BYOK endpoint.
// Evaluate persistent configuration when scheduling, not when a long-lived worker starts.
// The command and queued job recheck authorization independently under the shared lock.
Schedule::command('ii:sync-market-feed --queue')->everyFifteenMinutes()->withoutOverlapping()
    ->when(function (): bool {
        $cfg = app(\App\Services\MarketFeedSettings::class)->current();
        return ($cfg['enabled'] ?? false) === true && ($cfg['provider'] ?? 'local') === 'local';
    });

Schedule::command('ii:sync-market-feed --queue')->dailyAt('12:00')->timezone('UTC')->withoutOverlapping()
    ->when(function (): bool {
        $cfg = app(\App\Services\MarketFeedSettings::class)->current();
        return ($cfg['enabled'] ?? false) === true && ($cfg['provider'] ?? 'local') === 'twelve_data';
    });

Artisan::command('ii:reconcile-market-runs', function () {
    $count = app(\App\Services\MarketAcquisitionLedger::class)->reconcileInterrupted();
    $this->info('II_MARKET_INTERRUPTED_RECONCILED='.$count);
})->purpose('Mark abandoned market acquisition receipts as outcome unknown');

Schedule::command('ii:reconcile-market-runs')->hourly()->withoutOverlapping();

Schedule::command('ii:discover-market-universe')->dailyAt('10:00')->timezone('UTC')->withoutOverlapping()
    ->when(function (): bool {
        $cfg = app(\App\Services\MarketFeedSettings::class)->current();
        return ($cfg['discovery_enabled'] ?? false) === true && ($cfg['provider'] ?? null) === 'twelve_data';
    });

// Explicit owner opt-in; five immutable public candidates per 15-minute tick.
Schedule::command('ii:historical-cycle scheduled')->everyFifteenMinutes()->withoutOverlapping();

/** Explicit local manifest; never infer storage rights from an upload. */
Artisan::command('ii:idx-evidence-batch {action : preview|store} {--user=} {--manifest=}', function () {
    try {
        $action = $this->argument('action');
        $owner = filter_var($this->option('user'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $path = $this->option('manifest');
        if (!in_array($action,['preview','store'],true) || !$owner || !is_string($path) || !is_file($path)) {
            throw new \InvalidArgumentException('II_IDX_BATCH_ARGUMENTS_INVALID');
        }
        $raw = file_get_contents($path,false,null,0,100001);
        if (!is_string($raw) || strlen($raw)>100000) {
            throw new \InvalidArgumentException('II_IDX_BATCH_MANIFEST_INVALID');
        }
        $pairs = json_decode($raw,true,12,JSON_THROW_ON_ERROR);
        if (!is_array($pairs)) {
            throw new \InvalidArgumentException('II_IDX_BATCH_MANIFEST_INVALID');
        }
        $result = app(\App\Services\IdxDailyEvidenceWarehouse::class)->ingestBatch($owner,$pairs,$action==='store');
        $this->line(json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        return 0;
    } catch (\Throwable $e) {
        $this->error('II_IDX_BATCH_FAILED: '.($e instanceof \InvalidArgumentException ? $e->getMessage() : 'Check manifest, existing evidence and runtime.'));
        return 1;
    }
})->purpose('Preview or atomically store reviewed local IDX evidence pairs');
