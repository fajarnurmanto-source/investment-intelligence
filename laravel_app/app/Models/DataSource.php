<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataSource extends Model
{
    protected $table = 'ii_data_sources';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'name', 'source_type', 'base_url', 'terms_url', 'rights_status', 'attribution_text', 'provenance', 'last_success_at'];

    protected function casts(): array
    {
        return ['provenance' => 'array', 'last_success_at' => 'datetime'];
    }
}
