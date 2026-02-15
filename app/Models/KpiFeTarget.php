<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiFeTarget extends Model
{
    protected $table = 'kpi_fe_targets';

    protected $fillable = [
        'period',
        'fe_user_id',
        'ao_code',
        'target_os_turun_kol2',
        'target_migrasi_npl_pct',
        'target_penalty_paid',
        'created_by',
    ];

    protected $casts = [
        'period' => 'date',
        'target_os_turun_kol2'   => 'decimal:2',
        'target_migrasi_npl_pct' => 'decimal:4',
        'target_penalty_paid'    => 'decimal:2',
        'fe_user_id' => 'integer',
        'created_by' => 'integer',
    ];

    // ===== Relations =====
    public function feUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fe_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ===== Scopes =====
    public function scopePeriod(Builder $q, string $periodDate): Builder
    {
        return $q->whereDate('period', '=', $periodDate);
    }

    public function scopeForFe(Builder $q, int $feUserId): Builder
    {
        return $q->where('fe_user_id', $feUserId);
    }
}
