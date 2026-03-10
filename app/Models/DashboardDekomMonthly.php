<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardDekomMonthly extends Model
{
    protected $table = 'dashboard_dekom_monthlies';

    protected $fillable = [
        'period_month',
        'as_of_date',
        'calc_mode',

        'total_os',
        'total_noa',

        'ft0_os',
        'ft0_noa',
        'ft1_os',
        'ft1_noa',
        'ft2_os',
        'ft2_noa',
        'ft3_os',
        'ft3_noa',

        'l_os',
        'l_noa',
        'dpk_os',
        'dpk_noa',
        'kl_os',
        'kl_noa',
        'd_os',
        'd_noa',
        'm_os',
        'm_noa',

        'npl_os',
        'npl_noa',
        'npl_pct',
        'kkr_pct',

        'restr_os',
        'restr_noa',

        'mtd_real_os',
        'mtd_real_noa',
        'ytd_real_os',
        'ytd_real_noa',

        'dpd6_os',
        'dpd6_noa',
        'dpd12_os',
        'dpd12_noa',

        'target_os',
        'target_npl_pct',
        'ach_os_pct',

        'mom_os_growth_pct',
        'yoy_os_growth_pct',

        'meta',
    ];

    protected $casts = [
        'period_month'        => 'date',
        'as_of_date'          => 'date',
        'meta'                => 'array',

        'total_os'            => 'decimal:2',
        'ft0_os'              => 'decimal:2',
        'ft1_os'              => 'decimal:2',
        'ft2_os'              => 'decimal:2',
        'ft3_os'              => 'decimal:2',
        'l_os'                => 'decimal:2',
        'dpk_os'              => 'decimal:2',
        'kl_os'               => 'decimal:2',
        'd_os'                => 'decimal:2',
        'm_os'                => 'decimal:2',
        'npl_os'              => 'decimal:2',
        'restr_os'            => 'decimal:2',
        'mtd_real_os'         => 'decimal:2',
        'ytd_real_os'         => 'decimal:2',
        'dpd6_os'             => 'decimal:2',
        'dpd12_os'            => 'decimal:2',
        'target_os'           => 'decimal:2',

        'npl_pct'             => 'decimal:4',
        'kkr_pct'             => 'decimal:4',
        'target_npl_pct'      => 'decimal:4',
        'ach_os_pct'          => 'decimal:4',
        'mom_os_growth_pct'   => 'decimal:4',
        'yoy_os_growth_pct'   => 'decimal:4',
    ];
}