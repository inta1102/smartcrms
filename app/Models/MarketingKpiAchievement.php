<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MarketingKpiAchievement extends Model
{
    protected $table = 'marketing_kpi_achievements';

    protected $fillable = [
        'target_id','user_id','period',
        'os_source_now','os_source_prev',
        'position_date_now','position_date_prev',
        'is_final',
        'os_end_now','os_end_prev','os_growth',
        'noa_end_now','noa_end_prev','noa_growth',
        'os_ach_pct','noa_ach_pct',
        'score_os','score_noa','score_total',
    ];

    protected $casts = [
        'period'             => 'date:Y-m-d',
        'position_date_now'  => 'date:Y-m-d',
        'position_date_prev' => 'date:Y-m-d',
        'is_final'           => 'boolean',

        'os_end_now'  => 'decimal:2',
        'os_end_prev' => 'decimal:2',
        'os_growth'   => 'decimal:2',
        'os_ach_pct'  => 'decimal:2',
        'noa_ach_pct' => 'decimal:2',
        'score_os'    => 'decimal:2',
        'score_noa'   => 'decimal:2',
        'score_total' => 'decimal:2',
    ];

    public function target(): BelongsTo
    {
        return $this->belongsTo(MarketingKpiTarget::class, 'target_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function achievement(): HasOne
    {
        return $this->hasOne(\App\Models\MarketingKpiAchievement::class, 'target_id');
    }

}
