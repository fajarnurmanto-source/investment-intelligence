<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IssuerCorporateAction extends Model
{
    use HasUuids;

    protected $table = 'ii_issuer_corporate_actions';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['effective_on' => 'date'];
    }
}
