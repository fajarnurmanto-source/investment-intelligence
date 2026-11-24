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

/*
 * Explicit opt-in only: 10 tickers x three statements x ~4 weekly runs
 * consumes ~120 of the advertised 1,000 Free-tier calls/month.
 * Each result is a private JSON review bundle, not an approved trade signal.
 */
Schedule::command('ii:idxapi:sync')
    ->weeklyOn(1, '06:25')
    ->timezone('Asia/Jakarta')
    ->when(fn () => (bool) config('ii.idxapi.auto_sync', false)
        && trim((string) config('ii.idxapi.key', '')) !== ''
        && trim((string) config('ii.idxapi.tickers', '')) !== '')
    ->withoutOverlapping();
