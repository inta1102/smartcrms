<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiRoMonthly extends Model
{
    protected $table = 'kpi_ro_monthly';

    protected $fillable = [
        'period_month','branch_code','ao_code',
        'topup_realisasi','topup_target','topup_pct','topup_score',
        'topup_cif_count','topup_cif_new_count','topup_max_cif_amount','topup_concentration_pct','topup_top3_json',
        'repayment_rate','repayment_pct','repayment_score',
        'repayment_total_os','repayment_os_lancar',
        'noa_realisasi','noa_target','noa_pct','noa_score',
        'dpk_pct','dpk_score','dpk_migrasi_count','dpk_migrasi_os','dpk_total_os_akhir',
        'total_score_weighted',
        'calc_mode','start_snapshot_month','end_snapshot_month','calc_source_position_date',
        'baseline_ok','baseline_note','locked_at'
    ];

    protected $casts = [
        'period_month' => 'date',
        'start_snapshot_month' => 'date',
        'end_snapshot_month' => 'date',
        'calc_source_position_date' => 'date',
        'locked_at' => 'datetime',
    ];

    public function isLocked(): bool
    {
        return !is_null($this->locked_at);
    }
}
