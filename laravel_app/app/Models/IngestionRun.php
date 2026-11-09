<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IngestionRun extends Model
{
    use HasUuids;

    protected $table = 'ii_ingestion_runs';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'source_id', 'series_id', 'status', 'source_url', 'started_at', 'completed_at', 'records_received', 'records_written', 'missing_records', 'response_sha256', 'failure_reason', 'quality'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime', 'quality' => 'array'];
    }
}
