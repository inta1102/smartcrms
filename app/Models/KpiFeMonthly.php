<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiFeMonthly extends Model
{
    protected $table = 'kpi_fe_monthlies';

    protected $fillable = [
        'period',
        'calc_mode',
        'fe_user_id',
        'ao_code',

        'os_kol2_awal',
        'os_kol2_akhir',
        'os_kol2_turun',

        'migrasi_npl_os',
        'migrasi_npl_pct',

        'penalty_paid_total',

        'target_os_turun_kol2',
        'target_migrasi_npl_pct',
        'target_penalty_paid',

        'ach_os_turun_pct',
        'ach_migrasi_pct',
        'ach_penalty_pct',

        'score_os_turun',
        'score_migrasi',
        'score_penalty',

        'pi_os_turun',
        'pi_migrasi',
        'pi_penalty',
        'total_score_weighted',

        'baseline_ok',
        'baseline_note',

        'calculated_by',
        'calculated_at',
    ];

    protected $casts = [
        'period' => 'date',
        'calculated_at' => 'datetime',

        // numeric
        'os_kol2_awal'  => 'decimal:2',
        'os_kol2_akhir' => 'decimal:2',
        'os_kol2_turun' => 'decimal:2',

        'migrasi_npl_os'  => 'decimal:2',
        'migrasi_npl_pct' => 'decimal:4',

        'penalty_paid_total' => 'decimal:2',

        'target_os_turun_kol2'   => 'decimal:2',
        'target_migrasi_npl_pct' => 'decimal:4',
        'target_penalty_paid'    => 'decimal:2',

        'ach_os_turun_pct' => 'decimal:2',
        'ach_migrasi_pct'  => 'decimal:2',
        'ach_penalty_pct'  => 'decimal:2',

        'score_os_turun' => 'decimal:2',
        'score_migrasi'  => 'decimal:2',
        'score_penalty'  => 'decimal:2',

        'pi_os_turun' => 'decimal:2',
        'pi_migrasi'  => 'decimal:2',
        'pi_penalty'  => 'decimal:2',
        'total_score_weighted' => 'decimal:2',

        'baseline_ok' => 'boolean',

        'fe_user_id'     => 'integer',
        'calculated_by'  => 'integer',
    ];

    // ===== Relations =====
    public function feUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fe_user_id');
    }

    public function calculator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }

    // ===== Scopes =====
    public function scopePeriod(Builder $q, string $periodDate): Builder
    {
        return $q->whereDate('period', '=', $periodDate);
    }

    public function scopeMode(Builder $q, string $mode): Builder
    {
        return $q->where('calc_mode', $mode);
    }

    public function scopeForFe(Builder $q, int $feUserId): Builder
    {
        return $q->where('fe_user_id', $feUserId);
    }
}
