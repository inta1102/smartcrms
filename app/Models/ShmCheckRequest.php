<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;


class ShmCheckRequest extends Model
{
    protected $table = 'shm_check_requests';

    // status constants
    public const STATUS_SUBMITTED        = 'SUBMITTED';
    public const STATUS_SENT_TO_NOTARY   = 'SENT_TO_NOTARY';
    public const STATUS_WAITING_SP_SK    = 'WAITING_SP_SK';
    public const STATUS_SP_SK_UPLOADED   = 'SP_SK_UPLOADED';
    public const STATUS_SIGNED_UPLOADED  = 'SIGNED_UPLOADED';
    public const STATUS_HANDED_TO_SAD    = 'HANDED_TO_SAD';
    public const STATUS_SENT_TO_BPN      = 'SENT_TO_BPN';
    public const STATUS_RESULT_UPLOADED  = 'RESULT_UPLOADED';
    public const STATUS_CLOSED           = 'CLOSED';
    public const STATUS_REJECTED         = 'REJECTED';
    // ✅ Status revisi
    public const STATUS_REVISION_REQUESTED = 'REVISION_REQUESTED';
    public const STATUS_REVISION_APPROVED  = 'REVISION_APPROVED';

    protected $fillable = [
        'request_no','requested_by','branch_code','ao_code',
        'debtor_name','debtor_phone','collateral_address','certificate_no','notary_name',
        'status','submitted_at','sent_to_notary_at','sp_sk_uploaded_at','signed_uploaded_at',
        'handed_to_sad_at','sent_to_bpn_at','result_uploaded_at','closed_at','notes', 'is_jogja','initial_files_locked_at',
        'initial_files_locked_by',
        'revision_requested_at',
        'revision_requested_by',
        'revision_reason',
        'revision_approved_at',
        'revision_approved_by',
        'revision_approval_notes',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'sent_to_notary_at' => 'datetime',
        'sp_sk_uploaded_at' => 'datetime',
        'signed_uploaded_at' => 'datetime',
        'handed_to_sad_at' => 'datetime',
        'sent_to_bpn_at' => 'datetime',
        'result_uploaded_at' => 'datetime',
        'closed_at' => 'datetime',

        'is_jogja' => 'boolean',

        'initial_files_locked_at' => 'datetime',
        'revision_requested_at' => 'datetime',
        'revision_approved_at' => 'datetime',
    ];


    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ShmCheckRequestFile::class, 'request_id')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ShmCheckRequestLog::class, 'request_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }


    // App\Models\ShmCheckRequest.php

    public function scopeVisibleFor(Builder $q, User $user): Builder
    {
        $rv = strtoupper(trim($user->roleValue() ?? $user->level ?? ''));

        // =========================
        // 1) SAD / KSA / KBO
        // =========================
        if (in_array($rv, ['KSA', 'KBO', 'SAD'], true)) {

            // Kalau mau per-cabang (umum)
            // return $q->where('branch_code', $user->branch_code);

            // Kalau SAD/KSA boleh lihat semua cabang (opsi)
            return $q;
        }

        // =========================
        // 2) Leadership (TL/KSLR/KBL)
        // =========================
        if (in_array($rv, ['KSLR','KBL','TLRO','TLSO','TLUM','TLFE','TLBE','KSBE','KSFE'], true)) {

            $ids = $this->resolveSubordinateUserIds($user);

            // fail-safe: kalau belum ada mapping org_assignments, minimal lihat miliknya sendiri
            if (empty($ids)) {
                return $q->where('requested_by', $user->id);
            }

            // pemohon bawahan + dirinya (kadang leader juga bisa bikin request)
            $ids[] = $user->id;
            $ids = array_values(array_unique($ids));

            return $q->whereIn('requested_by', $ids);
        }

        // =========================
        // 3) Default: pemohon
        // =========================
        return $q->where('requested_by', $user->id);
    }

    /**
     * Multi-level subordinate resolution via org_assignments.
     * Asumsi tabel: org_assignments(leader_id, user_id, is_active, effective_from/effective_to optional)
     */
    protected function resolveSubordinateUserIds(User $leader): array
    {
        // ambil subordinates level-1
        $seen = [];
        $queue = DB::table('org_assignments')
            ->where('leader_id', $leader->id)
            ->where('is_active', 1)
            ->pluck('user_id')
            ->toArray();

        while (!empty($queue)) {
            $uid = array_shift($queue);
            if (isset($seen[$uid])) continue;
            $seen[$uid] = true;

            // ambil anak dari anak (recursive BFS)
            $children = DB::table('org_assignments')
                ->where('leader_id', $uid)
                ->where('is_active', 1)
                ->pluck('user_id')
                ->toArray();

            foreach ($children as $c) {
                if (!isset($seen[$c])) $queue[] = $c;
            }
        }

        return array_keys($seen);
    }

    public const NOTARIES = [
        'Justicia',
        'Imam',
        'Ricky',
    ];

    // App\Models\ShmCheckRequest.php

    public function getProgressLabelAttribute(): string
    {
        $s = (string)($this->status ?? '');

        // revisi prioritas
        if ($s === self::STATUS_REVISION_REQUESTED) return 'Revisi diminta (menunggu approval SAD/KSA)';
        if ($s === self::STATUS_REVISION_APPROVED)  return 'Revisi disetujui (menunggu upload AO)';

        if ($this->closed_at || $s === self::STATUS_CLOSED) return 'Closed (selesai)';
        if ($this->result_uploaded_at) return 'Hasil diupload (menunggu closing)';
        if ($this->sent_to_bpn_at) return 'Proses BPN (menunggu hasil)';
        if ($this->handed_to_sad_at) return 'Berkas fisik diserahkan ke KSA/KBO';
        if ($this->signed_uploaded_at) return 'Signed diupload (menunggu serah fisik)';
        if ($this->sp_sk_uploaded_at) return 'SP/SK diupload (menunggu tanda tangan debitur)';
        if ($this->sent_to_notary_at) return 'Ke Notaris (menunggu SP/SK)';
        if ($this->submitted_at) return 'Submitted (menunggu diproses SAD/KSA)';

        return 'Draft/Unknown';
    }
}
