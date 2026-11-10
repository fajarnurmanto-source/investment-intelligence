<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IssuerMarketPrice extends Model
{
    use HasUuids;

    protected $table = 'ii_issuer_market_prices';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['observed_on' => 'date', 'redistribution_allowed' => 'boolean', 'imported_at' => 'immutable_datetime'];
    }
}
