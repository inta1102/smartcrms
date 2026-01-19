<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class NonLitigationAction extends Model
{
    use HasFactory;

    protected $table = 'non_litigation_actions';

    protected $fillable = [
        'npl_case_id',

        'action_type',
        'status',

        'proposed_by',
        'proposed_by_name',
        'proposal_at',
        'proposal_summary',
        'proposal_detail',

        'commitment_amount',
        'installment_plan',

        'effective_date',
        'monitoring_next_due',

        'approved_by',
        'approved_by_name',
        'approved_at',
        'approval_notes',

        'rejected_by',
        'rejected_by_name',
        'rejected_at',
        'rejection_notes',

        'case_action_id',

        'source_system',
        'source_ref_id',
        'meta',
        'needs_tl_approval',
    ];

    protected $casts = [
        'proposal_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',

        'effective_date' => 'date',
        'monitoring_next_due' => 'date',

        // Karena kolom disimpan sebagai longText, cast ke array biar enak dipakai
        'proposal_detail' => 'array',
        'installment_plan' => 'array',
        'meta' => 'array',

        'commitment_amount' => 'decimal:2',
    ];

    /**
     * Status constants (biar konsisten di controller/service)
     */
    public const STATUS_DRAFT       = 'draft';
    public const STATUS_SUBMITTED   = 'submitted';

    // ✅ tambahkan ini
    public const STATUS_PENDING_TL  = 'pending_tl';
    public const STATUS_PENDING_KASI= 'pending_kasi';

    public const STATUS_APPROVED    = 'approved';
    public const STATUS_REJECTED    = 'rejected';
    public const STATUS_CANCELED    = 'canceled';
    public const STATUS_COMPLETED   = 'completed';

    /**
     * Action type constants (opsional, tapi enak buat validasi)
     */
    public const TYPE_RESTRUCT          = 'restruct';
    public const TYPE_RESCHEDULE        = 'reschedule';
    public const TYPE_RECONDITION       = 'recondition';
    public const TYPE_NOVASI            = 'novasi';
    public const TYPE_SETTLEMENT        = 'settlement';
    public const TYPE_PTP               = 'ptp';
    public const TYPE_DISCOUNT_INTEREST = 'discount_interest';
    public const TYPE_WAIVE_PENALTY      = 'waive_penalty';

    public function nplCase()
    {
        return $this->belongsTo(NplCase::class, 'npl_case_id');
    }

    public function caseAction()
    {
        return $this->belongsTo(CaseAction::class, 'case_action_id');
    }

    /**
     * Scopes kecil untuk memudahkan query
     */
    public function scopeOpen($q)
    {
        return $q->whereIn('status', [
            self::STATUS_DRAFT,
            self::STATUS_SUBMITTED,
            self::STATUS_APPROVED,
        ]);
    }

    public function scopeApproved($q)
    {
        return $q->where('status', self::STATUS_APPROVED);
    }

    public function scopeSubmitted($q)
    {
        return $q->where('status', self::STATUS_SUBMITTED);
    }

    /**
     * Helper: apakah action ini sudah final (diterima/ditolak)
     */
    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELED], true);
    }

    public function schedules()
    {
        return $this->morphMany(\App\Models\ActionSchedule::class, 'schedulable');
    }

    public function case()
    {
        return $this->belongsTo(\App\Models\NplCase::class, 'npl_case_id');
    }

    public function proposer()
    {
        return $this->belongsTo(\App\Models\User::class, 'proposed_by');
    }

    public function isPendingTl(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING_TL, self::STATUS_SUBMITTED], true);
    }

    public function isPendingKasi(): bool
    {
        return $this->status === self::STATUS_PENDING_KASI;
    }

}
