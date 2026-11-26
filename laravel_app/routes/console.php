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
