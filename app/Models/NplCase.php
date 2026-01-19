<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;

use App\Models\LegalAction;
use App\Models\AoAgenda;
use App\Models\CaseAction;
use App\Models\OrgAssignment;
use App\Models\User;
use App\Models\ActionSchedule;
use App\Models\CaseResolutionTarget;
use App\Models\LoanAccount;

use App\Enums\UserRole;

class NplCase extends Model
{
    use HasFactory;

    protected $table = 'npl_cases';

    protected $fillable = [
        'loan_account_id',
        'pic_user_id',
        'status',
        'priority',
        'opened_at',
        'closed_at',
        'closed_reason',
        'closed_by',
        'reopened_at',
        'summary',

        // legacy sync
        'last_legacy_sync_at',
        'legacy_sp_fingerprint',

        // ✅ legal fields (baru)
        'is_legal',
        'legal_started_at',
        'legal_note',
    ];

    protected $casts = [
        'opened_at'        => 'date',
        'closed_at'        => 'date',
        'reopened_at'      => 'date',
        'last_legacy_sync_at' => 'datetime',

        // ✅ legal fields
        'is_legal'         => 'boolean',
        'legal_started_at' => 'datetime',
    ];

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function loanAccount()
    {
        return $this->belongsTo(LoanAccount::class, 'loan_account_id');
    }

    public function actions()
    {
        return $this->hasMany(CaseAction::class, 'npl_case_id');
    }

    public function isOverdueNextAction()
    {
        $next = $this->actions->sortByDesc('action_at')->first();
        if (!$next || !$next->next_action_due) return false;

        return now()->toDateString() > $next->next_action_due->toDateString();
    }

    public function schedules()
    {
        return $this->hasMany(ActionSchedule::class);
    }

    public function visits()
    {
        return $this->hasMany(\App\Models\VisitLog::class);
    }

    public function legalCase()
    {
        return $this->hasOne(\App\Models\LegalCase::class, 'npl_case_id');
    }

    public function legalActions()
    {
        return $this->hasMany(LegalAction::class, 'npl_case_id');
    }

    public function resolutionTargets()
    {
        return $this->hasMany(CaseResolutionTarget::class, 'npl_case_id');
    }

    public function activeResolutionTarget()
    {
        return $this->hasOne(CaseResolutionTarget::class)
            ->whereIn('status', ['APPROVED_KASI', 'ACTIVE'])
            ->latestOfMany();
    }

    public function pendingResolutionTarget()
    {
        return $this->hasOne(CaseResolutionTarget::class)
            ->whereIn('status', ['PENDING_TL', 'PENDING_KASI'])
            ->latestOfMany();
    }

    public function aoAgendas()
    {
        return $this->hasMany(AoAgenda::class, 'npl_case_id');
    }

    public function activeTargetAgendas()
    {
        return $this->aoAgendas()
            ->whereHas('target', fn ($t) => $t->where('is_active', 1))
            ->latest('due_at');
    }

    public function latestDueAction(): HasOne
    {
        return $this->hasOne(CaseAction::class, 'npl_case_id')
            ->ofMany([
                'action_at' => 'max',
                'id'        => 'max',
            ], function ($q) {
                $q->whereNotNull('next_action_due');
            });
    }

    public function scopeOwnedByFieldStaff($q, User $u)
    {
        return $q->where('pic_user_id', $u->id);
    }

    /**
     * ✅ VISIBILITY SCOPE
     * Catatan: BE kita buat hybrid:
     * - lihat semua case legal (is_legal=1)
     * - + case miliknya sendiri (pic_user_id)
     */
    public function scopeVisibleFor(Builder $q, User $user): Builder
    {
        // ===============================
        // MANAGEMENT / TOP (lihat semua)
        // ===============================
        if ($user->hasAnyRole([
            UserRole::DIREKSI, UserRole::KOM, UserRole::DIR,
            UserRole::KABAG, UserRole::KBL, UserRole::KBO, UserRole::KTI, UserRole::KBF,
            UserRole::PE,
        ])) {
            return $q; // ✅ no filter
        }

        // ===============================
        // BE (Legal) - HYBRID
        // ===============================
        if ($user->hasAnyRole([UserRole::BE])) {
            return $q->where(function ($qq) use ($user) {
                $qq->where('pic_user_id', $user->id)
                   ->orWhere('is_legal', 1);
            });
        }

        // ===============================
        // AO / FE / SO / RO / SA (field staff biasa)
        // ===============================
        if ($user->hasAnyRole([UserRole::AO, UserRole::FE, UserRole::SO, UserRole::RO, UserRole::SA])) {
            return $q->where('pic_user_id', $user->id);
        }

        // ===============================
        // TL / TLL / TLF / TLR
        // ===============================
        if ($user->hasAnyRole([UserRole::TL, UserRole::TLL, UserRole::TLF, UserRole::TLR])) {
            $staffIds = OrgAssignment::query()
                ->where('leader_id', $user->id)
                ->where('is_active', 1)
                ->whereNull('effective_to')
                ->pluck('user_id')
                ->all();

            return empty($staffIds)
                ? $q->whereRaw('1=0')
                : $q->whereIn('pic_user_id', $staffIds);
        }

        // ===============================
        // KASI (hybrid)
        // ===============================
        if ($user->hasAnyRole([UserRole::KSL, UserRole::KSO, UserRole::KSA, UserRole::KSF, UserRole::KSD, UserRole::KSR])) {

            $directIds = OrgAssignment::query()
                ->where('leader_id', $user->id)
                ->where('is_active', 1)
                ->whereNull('effective_to')
                ->pluck('user_id')
                ->all();

            if (empty($directIds)) {
                return $q->whereRaw('1=0');
            }

            $tlIds = User::query()
                ->whereIn('id', $directIds)
                ->whereIn('level', [UserRole::TL, UserRole::TLL, UserRole::TLF, UserRole::TLR])
                ->pluck('id')
                ->all();

            $directStaffIds = User::query()
                ->whereIn('id', $directIds)
                ->whereIn('level', [UserRole::AO, UserRole::BE, UserRole::FE, UserRole::SO, UserRole::RO, UserRole::SA])
                ->pluck('id')
                ->all();

            $staffFromTlIds = [];
            if (!empty($tlIds)) {
                $staffFromTlIds = OrgAssignment::query()
                    ->whereIn('leader_id', $tlIds)
                    ->where('is_active', 1)
                    ->whereNull('effective_to')
                    ->pluck('user_id')
                    ->all();

                $staffFromTlIds = User::query()
                    ->whereIn('id', $staffFromTlIds)
                    ->whereIn('level', [UserRole::AO, UserRole::BE, UserRole::FE, UserRole::SO])
                    ->pluck('id')
                    ->all();
            }

            $visiblePicIds = array_values(array_unique(array_merge($directStaffIds, $staffFromTlIds)));

            return empty($visiblePicIds)
                ? $q->whereRaw('1=0')
                : $q->whereIn('pic_user_id', $visiblePicIds);
        }

        // ===============================
        // DEFAULT (AMAN)
        // ===============================
        return $q->whereRaw('1=0');
    }

    public function canStartLitigation(): bool
    {
        return app(\App\Services\Legal\LitigationEligibilityService::class)->canStart($this);
    }

    public function litigationChecklist(): array
    {
        return app(\App\Services\Legal\LitigationEligibilityService::class)->evaluate($this);
    }

    public function legalProposals()
    {
        return $this->hasMany(\App\Models\LegalActionProposal::class, 'npl_case_id');
    }

    /**
     * Proposal aktif terbaru (1 case biasanya 1 aktif).
     */
    public function latestLegalProposal()
    {
        return $this->hasOne(\App\Models\LegalActionProposal::class, 'npl_case_id')
            ->latestOfMany(); // berdasarkan created_at
    }

    public function pic()
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }

}
