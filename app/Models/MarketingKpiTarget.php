<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingKpiTarget extends Model
{
    protected $table = 'marketing_kpi_targets';

    // ===== Status constants =====
    public const STATUS_DRAFT        = 'DRAFT';
    public const STATUS_PENDING_TL   = 'PENDING_TL';
    public const STATUS_PENDING_KASI = 'PENDING_KASI';
    public const STATUS_APPROVED     = 'APPROVED';
    public const STATUS_REJECTED     = 'REJECTED';


    protected $fillable = [
        'period',
        'user_id',
        'branch_code',
        'target_os_growth',
        'target_noa',
        'weight_os',
        'weight_noa',
        'status',
        'proposed_by',
        'approved_by',
        'approved_at',
        'notes',
        'is_locked',
    ];

    protected $casts = [
        'period'           => 'date:Y-m-d',
        'target_os_growth' => 'decimal:2',
        'target_noa'       => 'integer',
        'weight_os'        => 'integer',
        'weight_noa'       => 'integer',
        'approved_at'      => 'datetime',
        'is_locked'        => 'boolean',
    ];

    // ===== Relations =====
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ===== Scopes =====
    public function scopeForPeriod(Builder $q, string $periodYmd): Builder
    {
        return $q->whereDate('period', $periodYmd);
    }

    public function scopeUnlocked(Builder $q): Builder
    {
        return $q->where('is_locked', false);
    }

    public function scopeApproved(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_APPROVED);
    }

    public function achievement()
    {
        return $this->hasOne(\App\Models\MarketingKpiAchievement::class, 'target_id');
    }

}
