<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IssuerImportBatch extends Model
{
    use HasUuids;

    protected $table = 'ii_issuer_import_batches';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['error_summary' => 'array', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function items()
    {
        return $this->hasMany(IssuerImportItem::class, 'batch_id');
    }
}
