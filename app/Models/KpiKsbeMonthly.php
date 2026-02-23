<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiKsbeMonthly extends Model
{
    protected $table = 'kpi_ksbe_monthlies';

    protected $fillable = [
        'period','ksbe_user_id',
        'scope_be_count','active_be_count','coverage_pct',
        'target_os_selesai','target_noa_selesai','target_bunga_masuk','target_denda_masuk',
        'actual_os_selesai','actual_noa_selesai','actual_bunga_masuk','actual_denda_masuk',
        'os_npl_prev','os_npl_now','net_npl_drop','npl_drop_pct',
        'ach_os','ach_noa','ach_bunga','ach_denda',
        'score_os','score_noa','score_bunga','score_denda',
        'pi_os','pi_noa','pi_bunga','pi_denda','pi_scope_total',
        'pi_stddev','bottom_be_count','bottom_pct',
        'si_coverage_score','si_spread_score','si_bottom_score','si_total',
        'ri_score',
        'prev_pi_scope_total','delta_pi','ii_score',
        'li_total',
        'json_insights',
        'calculated_at',
    ];

    protected $casts = [
        'period' => 'date',
        'json_insights' => 'array',
        'calculated_at' => 'datetime',
    ];
}