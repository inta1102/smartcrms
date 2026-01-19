<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class CaseResolutionTarget extends Model
{
    protected $table = 'case_resolution_targets';

    // Status
    public const STATUS_PENDING_TL   = 'pending_tl';
    public const STATUS_PENDING_KASI = 'pending_kasi';
    public const STATUS_ACTIVE       = 'active';
    public const STATUS_REJECTED     = 'rejected';
    public const STATUS_SUPERSEDED   = 'superseded';

    protected $fillable = [
        'npl_case_id',
        'target_date',
        'strategy',
        'target_outcome',

        'status',
        'is_active',

        'needs_tl_approval',

        'proposed_by',
        'reason',

        'tl_approved_by',
        'tl_approved_at',
        'tl_notes',

        'kasi_approved_by',
        'kasi_approved_at',
        'kasi_notes',

        'approved_by',
        'approved_at',

        'rejected_by',
        'rejected_at',
        'reject_reason',

        'activated_by',
        'activated_at',

        'deactivated_by',
        'deactivated_at',
        'deactivated_reason',
    ];

    protected $casts = [
        'target_date'     => 'date',
        'approved_at'     => 'datetime',
        'tl_approved_at'  => 'datetime',
        'kasi_approved_at'=> 'datetime',
        'rejected_at'     => 'datetime',
        'activated_at'    => 'datetime',
        'deactivated_at'  => 'datetime',

        'is_active'       => 'boolean',
        'needs_tl_approval'=> 'boolean',
    ];

    // ✅ Relasi utama ke kasus
    public function nplCase()
    {
        return $this->belongsTo(NplCase::class, 'npl_case_id');
    }

    // ✅ Alias kalau ada kode lama pakai ->case()
    public function case()
    {
        return $this->nplCase();
    }

    public function proposer()
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function agendas()
    {
        return $this->hasMany(AoAgenda::class, 'resolution_target_id');
    }

    // =========================
    // Scopes
    // =========================
    public function scopePendingTl(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING_TL)
                 ->where('is_active', false);
    }

    public function scopePendingKasi(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING_KASI)
                 ->where('is_active', false);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE)
                 ->where('is_active', true);
    }

    public function scopeRejected(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_REJECTED);
    }
}
