<?php

namespace App\Services;

interface HistoricalMarketSnapshotSource
{
    /** Return an independently identified, bounded historical research snapshot. */
    public function snapshot(): array;
}
