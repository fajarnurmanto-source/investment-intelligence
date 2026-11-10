<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IssuerSource extends Model
{
    protected $table = 'ii_issuer_sources';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['redistribution_allowed' => 'boolean'];
    }
}
