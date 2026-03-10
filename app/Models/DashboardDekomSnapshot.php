<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardDekomSnapshot extends Model
{
    protected $table = 'dashboard_dekom_snapshots';

    protected $fillable = [
        'period_month',
        'as_of_date',
        'mode',

        'total_os',
        'total_noa',
        'npl_os',
        'npl_pct',

        'l_os',
        'dpk_os',
        'kl_os',
        'd_os',
        'm_os',

        'ft0_os',
        'ft1_os',
        'ft2_os',
        'ft3_os',

        'restr_os',
        'restr_noa',

        'dpd6_os',
        'dpd12_os',

        'target_ytd',
        'realisasi_mtd',
        'realisasi_ytd',

        'meta',
    ];

    protected $casts = [
        'period_month' => 'date',
        'as_of_date'   => 'date',
        'meta'         => 'array',

        'total_os'      => 'decimal:2',
        'npl_os'        => 'decimal:2',
        'npl_pct'       => 'decimal:4',

        'l_os'          => 'decimal:2',
        'dpk_os'        => 'decimal:2',
        'kl_os'         => 'decimal:2',
        'd_os'          => 'decimal:2',
        'm_os'          => 'decimal:2',

        'ft0_os'        => 'decimal:2',
        'ft1_os'        => 'decimal:2',
        'ft2_os'        => 'decimal:2',
        'ft3_os'        => 'decimal:2',

        'restr_os'      => 'decimal:2',
        'dpd6_os'       => 'decimal:2',
        'dpd12_os'      => 'decimal:2',

        'target_ytd'    => 'decimal:2',
        'realisasi_mtd' => 'decimal:2',
        'realisasi_ytd' => 'decimal:2',
    ];
}