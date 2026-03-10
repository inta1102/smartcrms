<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RbbCreditTarget extends Model
{
    protected $table = 'rbb_credit_targets';

    protected $fillable = [
        'period_month',
        'target_os',
        'target_npl_pct',
        'meta',
    ];

    protected $casts = [
        'period_month'   => 'date',
        'target_os'      => 'decimal:2',
        'target_npl_pct' => 'decimal:4',
        'meta'           => 'array',
    ];
}