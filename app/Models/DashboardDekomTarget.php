<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardDekomTarget extends Model
{
    protected $fillable = [
        'period_month',
        'target_disbursement',
        'target_os',
        'target_npl_pct',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'period_month' => 'date',
        'target_disbursement' => 'float',
        'target_os' => 'float',
        'target_npl_pct' => 'float',
    ];
}