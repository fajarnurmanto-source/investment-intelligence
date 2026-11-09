<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EconomicObservation extends Model
{
    protected $table = 'ii_economic_observations';
    protected $fillable = ['source_id', 'series_id', 'observed_on', 'value', 'units', 'frequency', 'quality_status', 'retrieved_at', 'ingestion_run_id', 'source_url', 'raw_checksum'];

    protected function casts(): array
    {
        return ['observed_on' => 'date', 'retrieved_at' => 'datetime', 'value' => 'decimal:8'];
    }
}
