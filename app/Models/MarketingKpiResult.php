<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingKpiResult extends Model
{
    protected $table = 'marketing_kpi_results';

    public const STATUS_DRAFT     = 'DRAFT';
    public const STATUS_FINALIZED = 'FINALIZED';

    protected $fillable = [
        'period',
        'user_id',

        'target_os_growth',
        'target_noa',

        'real_os_growth',
        'real_noa_new',

        'ratio_os',
        'ratio_noa',

        'score_os',
        'score_noa',
        'score_total',

        'cap_ratio',
        'status',

        'calculated_by',
        'calculated_at',

        'is_locked',
    ];

    protected $casts = [
        'period'          => 'date:Y-m-d',

        'target_os_growth'=> 'decimal:2',
        'target_noa'      => 'integer',

        'real_os_growth'  => 'decimal:2',
        'real_noa_new'    => 'integer',

        'ratio_os'        => 'decimal:4',
        'ratio_noa'       => 'decimal:4',

        'score_os'        => 'decimal:2',
        'score_noa'       => 'decimal:2',
        'score_total'     => 'decimal:2',

        'cap_ratio'       => 'decimal:2',

        'calculated_at'   => 'datetime',
        'is_locked'       => 'boolean',
    ];

    // ===== Relations =====
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function calculator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }

    // ===== Scopes =====
    public function scopeForPeriod(Builder $q, string $periodYmd): Builder
    {
        return $q->whereDate('period', $periodYmd);
    }

    public function scopeFinalized(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_FINALIZED);
    }

    public function scopeRanking(Builder $q): Builder
    {
        return $q->orderByDesc('score_total')->orderBy('user_id');
    }
}
