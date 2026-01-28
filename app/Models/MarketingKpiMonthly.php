<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingKpiMonthly extends Model
{
    protected $table = 'marketing_kpi_monthlies';

    protected $fillable = [
        'user_id','target_id','period',
        'target_os_growth','target_noa','weight_os','weight_noa',
        'os_end_now','os_end_prev','os_growth','os_source_now','os_source_prev',
        'position_date_now','position_date_prev',
        'noa_end_now','noa_end_prev','noa_growth',
        'os_ach_pct','noa_ach_pct','score_os','score_noa','score_total',
        'is_final','computed_at',
    ];

    protected $casts = [
        'period' => 'date',
        'position_date_now' => 'date',
        'position_date_prev' => 'date',
        'is_final' => 'boolean',
        'computed_at' => 'datetime',
        'os_ach_pct' => 'decimal:2',
        'noa_ach_pct' => 'decimal:2',
        'score_os' => 'decimal:2',
        'score_noa' => 'decimal:2',
        'score_total' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(MarketingKpiTarget::class, 'target_id');
    }
}
