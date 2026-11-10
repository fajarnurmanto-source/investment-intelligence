<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IssuerImportItem extends Model
{
    protected $table = 'ii_issuer_import_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'processed_at' => 'immutable_datetime'];
    }

    public function batch()
    {
        return $this->belongsTo(IssuerImportBatch::class, 'batch_id');
    }
}
