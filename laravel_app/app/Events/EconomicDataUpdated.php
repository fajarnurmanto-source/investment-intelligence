<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EconomicDataUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $seriesId,
        public readonly string $ingestionRunId,
        public readonly int $recordsWritten,
    ) {}
}
