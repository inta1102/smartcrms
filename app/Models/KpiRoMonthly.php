<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiRoMonthly extends Model
{
    protected $table = 'kpi_ro_monthly';

    protected $fillable = [
        'period_month',
        'branch_code',
        'ao_code',

        'topup_realisasi',
        'topup_target',
        'topup_pct',
        'topup_score',

        'repayment_rate',
        'repayment_pct',
        'repayment_score',

        'noa_realisasi',
        'noa_target',
        'noa_pct',
        'noa_score',

        'dpk_pct',
        'dpk_score',

        'total_score_weighted',

        'calc_mode',
        'start_snapshot_month',
        'end_snapshot_month',
        'calc_source_position_date',
        'locked_at',
        'baseline_ok',
        'baseline_note',
        'dpk_migrasi_count',
        'dpk_migrasi_os',
        'dpk_total_os_akhir',

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
