<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiRoManualActual extends Model
{
    protected $table = 'kpi_ro_manual_actuals';

    protected $fillable = [
        'period',
        'ao_code',
        'noa_pengembangan',
        'notes',
        'input_by',
        'input_at',
    ];

    protected $casts = [
        'period'   => 'date',
        'input_at' => 'datetime',
    ];
}