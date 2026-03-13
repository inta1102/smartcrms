<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardDekomMovement extends Model
{
    protected $fillable = [
        'period_month',
        'mode',
        'section',
        'subgroup',
        'line_key',
        'line_label',
        'noa_count',
        'os_amount',
        'plafond_baru',
        'sort_order',
        'is_total',
        'meta'
    ];

    protected $casts = [
        'period_month' => 'date',
        'meta' => 'array'
    ];
}
