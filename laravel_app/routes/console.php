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
if (config('market_feed.enabled') === true) {
    $marketFeed = Schedule::command('ii:sync-market-feed --queue')->withoutOverlapping();
    if (config('market_feed.provider') === 'twelve_data') {
        $marketFeed->dailyAt('12:00')->timezone('UTC');
    } else {
        $marketFeed->everyFifteenMinutes();
    }
}

Artisan::command('ii:reconcile-market-runs', function () {
    $count = app(\App\Services\MarketAcquisitionLedger::class)->reconcileInterrupted();
    $this->info('II_MARKET_INTERRUPTED_RECONCILED='.$count);
})->purpose('Mark abandoned market acquisition receipts as outcome unknown');

Schedule::command('ii:reconcile-market-runs')->hourly()->withoutOverlapping();
