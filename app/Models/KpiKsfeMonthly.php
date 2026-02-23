<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiKsfeMonthly extends Model
{
    protected $table = 'kpi_ksfe_monthlies';

    protected $fillable = [
        'period',
        'ksfe_id',
        'calc_mode',
        'tlfe_count',
        'pi_scope',
        'stability_index',
        'risk_index',
        'improvement_index',
        'leadership_index',
        'status_label',
        'meta',
    ];

    protected $casts = [
        'period' => 'date',
        'meta'   => 'array',
    ];

    public function ksfe()
    {
        return $this->belongsTo(User::class, 'ksfe_id');
    }
}