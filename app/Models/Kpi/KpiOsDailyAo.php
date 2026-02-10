<?php

namespace App\Models\Kpi;

use Illuminate\Database\Eloquent\Model;

class KpiOsDailyAo extends Model
{
    protected $table = 'kpi_os_daily_aos';

    protected $fillable = [
        'position_date',
        'ao_code',
        'os_total',
        'noa_total',
        'source',
        'computed_at',
    ];

    protected $casts = [
        'position_date' => 'date',
        'computed_at'   => 'datetime',
    ];
}
