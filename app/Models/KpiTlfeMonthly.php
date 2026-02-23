<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiTlfeMonthly extends Model
{
    protected $table = 'kpi_tlfe_monthlies';

    protected $fillable = [
        'period',
        'tlfe_id',
        'calc_mode',
        'fe_count',
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

    public function tlfe()
    {
        return $this->belongsTo(User::class, 'tlfe_id');
    }
}