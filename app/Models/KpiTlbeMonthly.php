<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiTlbeMonthly extends Model
{
    protected $table = 'kpi_tlbe_monthlies';

    protected $fillable = [
        'period','tlbe_user_id','scope_count',
        'target_os_sum','target_noa_sum','target_bunga_sum','target_denda_sum',
        'actual_os_sum','actual_noa_sum','actual_bunga_sum','actual_denda_sum',
        'ach_os_pct','ach_noa_pct','ach_bunga_pct','ach_denda_pct',
        'score_os','score_noa','score_bunga','score_denda',
        'pi_os','pi_noa','pi_bunga','pi_denda','team_pi',
        'avg_pi_be','coverage_pct','consistency_idx','total_pi',
        'calc_mode','status',
    ];

    protected $casts = [
        'period' => 'date',
    ];
}