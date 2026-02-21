<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiBeMonthly extends Model
{
    protected $table = 'kpi_be_monthlies';

    protected $fillable = [
        'period',
        'be_user_id',

        // =========================
        // LEGACY (4 metric awal)
        // =========================
        'actual_os_selesai',
        'actual_noa_selesai',
        'actual_bunga_masuk',
        'actual_denda_masuk',

        'score_os',
        'score_noa',
        'score_bunga',
        'score_denda',

        'pi_os',
        'pi_noa',
        'pi_bunga',
        'pi_denda',
        'total_pi',

        'os_npl_prev',
        'os_npl_now',
        'net_npl_drop',

        // =========================
        // ✅ BE3 (3 metric baru)
        // =========================
        // 1) Recovery principal (Rp)
        'actual_recovery_principal',
        'target_recovery_principal', // optional kalau kamu mau simpan target snapshot di monthly (opsional)
        'score_recovery',
        'pi_recovery',

        // 2) Lunas rate (%)
        'actual_exit_total',         // total exit bulan itu (LN+WO+AYDA+OTHER) atau minimal LN+WO+AYDA
        'actual_lunas_count',
        'actual_wo_count',
        'actual_ayda_count',
        'actual_other_exit_count',   // optional
        'actual_lunas_rate',         // % (0..100)
        'score_lunas_rate',
        'pi_lunas_rate',

        // 3) Risk exit rate (%)
        'actual_risk_exit_rate',     // % (WO+AYDA)/total_exit
        'score_risk_exit_rate',
        'pi_risk_exit_rate',

        // =========================
        // workflow
        // =========================
        'status',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'approved_by',
        'approval_note',
    ];

    protected $casts = [
        'period' => 'date',

        // legacy decimals
        'actual_os_selesai'   => 'decimal:2',
        'actual_bunga_masuk'  => 'decimal:2',
        'actual_denda_masuk'  => 'decimal:2',

        'pi_os'    => 'decimal:2',
        'pi_noa'   => 'decimal:2',
        'pi_bunga' => 'decimal:2',
        'pi_denda' => 'decimal:2',
        'total_pi' => 'decimal:2',

        'os_npl_prev'  => 'decimal:2',
        'os_npl_now'   => 'decimal:2',
        'net_npl_drop' => 'decimal:2',

        // =========================
        // ✅ BE3 casts
        // =========================
        'actual_recovery_principal' => 'decimal:2',
        'target_recovery_principal' => 'decimal:2',
        'pi_recovery' => 'decimal:2',

        'actual_lunas_rate' => 'decimal:2',
        'pi_lunas_rate' => 'decimal:2',

        'actual_risk_exit_rate' => 'decimal:2',
        'pi_risk_exit_rate' => 'decimal:2',

        // workflow timestamps
        'submitted_at' => 'datetime',
        'approved_at'  => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'be_user_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}