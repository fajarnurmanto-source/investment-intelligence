<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IssuerPeriod extends Model
{
    protected $table = 'ii_issuer_periods';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_end' => 'date', 'restated' => 'boolean', 'available_fields' => 'array'];
    }
}
