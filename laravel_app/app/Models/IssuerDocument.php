<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IssuerDocument extends Model
{
    use HasUuids;

    protected $table = 'ii_issuer_documents';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'report_date' => 'date', 'consolidated' => 'boolean', 'provenance' => 'array',
            'conflict_details' => 'array', 'taxonomy_namespaces' => 'array', 'imported_at' => 'immutable_datetime',
        ];
    }

    public function source()
    {
        return $this->belongsTo(IssuerSource::class, 'source_id');
    }

    public function periods()
    {
        return $this->hasMany(IssuerPeriod::class, 'document_id');
    }
}
