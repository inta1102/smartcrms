<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiRoTarget extends Model
{
    protected $table = 'kpi_ro_targets';

    protected $fillable = [
        'period',
        'ao_code',
        'target_topup',
        'target_noa',
        'target_rr_pct',
        'target_dpk_pct',
        'updated_by',
    ];

    protected $casts = [
        'period' => 'date',
        'target_topup' => 'float',
        'target_noa' => 'int',
        'target_rr_pct' => 'float',
        'target_dpk_pct' => 'float',
    ];
}
