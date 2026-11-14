<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiBudgetPeriod extends Model
{
    protected $fillable = [
        'period_type',
        'period_start',
        'limit_micro_usd',
        'reserved_micro_usd',
        'committed_micro_usd',
    ];

    protected function casts(): array
    {
        return ['period_start' => 'date'];
    }
}
