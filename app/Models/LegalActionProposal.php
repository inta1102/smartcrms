<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalActionProposal extends Model
{
    // =========================
    // Status Constants
    // =========================
    public const STATUS_PENDING_TL     = 'pending_tl';
    public const STATUS_APPROVED_TL    = 'approved_tl';

    // kalau kamu memang masih mau simpan pending_kasi, keep.
    // tapi dari controller kamu: antrian kasi = approved_tl
    public const STATUS_PENDING_KASI   = 'pending_kasi';

    public const STATUS_APPROVED_KASI  = 'approved_kasi';
    public const STATUS_REJECTED_TL    = 'rejected_tl';
    public const STATUS_REJECTED_KASI  = 'rejected_kasi';
    public const STATUS_EXECUTED       = 'executed';

    // (opsional) daftar status valid untuk validasi / UI
    public const STATUSES = [
        self::STATUS_PENDING_TL,
        self::STATUS_APPROVED_TL,
        self::STATUS_PENDING_KASI,
        self::STATUS_APPROVED_KASI,
        self::STATUS_REJECTED_TL,
        self::STATUS_REJECTED_KASI,
        self::STATUS_EXECUTED,
    ];

    protected $table = 'legal_action_proposals';

    protected $fillable = [
        'npl_case_id',
        'action_type',
        'reason',
        'notes',
        'status',
        'proposed_by',
        'submitted_at',
        'approved_tl_by',
        'approved_tl_at',
        'approved_tl_notes',
        'approved_kasi_by',
        'approved_kasi_at',
        'approved_kasi_notes',
        'executed_by',
        'executed_at',
        'legal_action_id',
    ];

    protected $casts = [
        'submitted_at'    => 'datetime',
        'approved_tl_at'  => 'datetime',
        'approved_kasi_at'=> 'datetime',
        'executed_at'     => 'datetime',
        'needs_tl_approval' => 'boolean',
    ];


    public function nplCase()
    {
        return $this->belongsTo(NplCase::class);
    }

    public function proposer()
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function legalAction()
    {
        return $this->belongsTo(LegalAction::class);
    }

    /* ================= Helpers ================= */

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isApprovedTl(): bool
    {
        return $this->status === self::STATUS_APPROVED_TL;
    }

    public function isApprovedKasi(): bool
    {
        return $this->status === self::STATUS_APPROVED_KASI;
    }

    public function isExecuted(): bool
    {
        return $this->status === self::STATUS_EXECUTED;
    }

}
