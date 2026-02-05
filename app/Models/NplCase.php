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
        'assessment',
        'assessment_updated_by',
        'assessment_updated_at',
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

    public function isOverdueNextAction(): bool
    {
        $a = $this->latestDueAction;
        if (!$a || !$a->next_action_due) return false;
        return now()->toDateString() > $a->next_action_due->toDateString();
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
            ->whereIn('status', ['active'])   // atau ['active','approved_kasi'] kalau ada
            ->latestOfMany();
    }

    public function pendingResolutionTarget()
    {
        return $this->hasOne(CaseResolutionTarget::class)
            ->whereIn('status', ['pending_tl','pending_kasi'])
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
            return $q;
        }

        // helper: normalisasi kode (leading zero)
        $normCodes = function (array $codes): array {
            $out = [];
            foreach ($codes as $c) {
                $c = strtoupper(trim((string) $c));
                if ($c === '') continue;

                $out[] = $c;

                $nz = ltrim($c, '0');
                if ($nz !== '') $out[] = $nz;

                if (ctype_digit($nz)) {
                    // sesuaikan panjang kalau ao_code kamu bukan 6 digit
                    $out[] = str_pad($nz, 6, '0', STR_PAD_LEFT);
                }
            }
            return array_values(array_unique($out));
        };

        // ===============================
        // FIELD STAFF (AO/BE/FE/SO/RO/SA)
        // ✅ hardening: PIC OR ao_code match
        // ===============================
        if ($user->hasAnyRole([UserRole::AO, UserRole::BE, UserRole::FE, UserRole::SO, UserRole::RO, UserRole::SA])) {
            $codes = $normCodes([
                (string) ($user->employee_code ?? ''),
                (string) ($user->username ?? ''),
            ]);

            return $q->where(function ($w) use ($user, $codes) {
                $w->where('pic_user_id', (int) $user->id);

                if (!empty($codes)) {
                    $w->orWhereHas('loanAccount', function ($x) use ($codes) {
                        $x->whereIn('ao_code', $codes);
                    });
                }
            });
        }

        // ===============================
        // TL / TLL / TLF / TLR
        // ===============================
        if ($user->hasAnyRole([UserRole::TL, UserRole::TLL, UserRole::TLF, UserRole::TLR])) {
            $staffIds = OrgAssignment::query()
                ->active()
                ->where('leader_id', (int) $user->id)
                ->pluck('user_id')
                ->map(fn($v) => (int) $v)
                ->values()
                ->all();

            return empty($staffIds)
                ? $q->whereRaw('1=0')
                : $q->whereIn('pic_user_id', $staffIds);
        }

        // ===============================
        // KASI (KSL/KSO/KSA/KSF/KSD/KSR)
        // - Direct bawahan: bisa TL atau staff
        // - Ambil staff under TL juga
        // ===============================
        if ($user->hasAnyRole([UserRole::KSL, UserRole::KSO, UserRole::KSA, UserRole::KSF, UserRole::KSD, UserRole::KSR])) {

            $directIds = OrgAssignment::query()
                ->active()
                ->where('leader_id', (int) $user->id)
                ->pluck('user_id')
                ->map(fn($v) => (int) $v)
                ->values()
                ->all();

            if (empty($directIds)) {
                return $q->whereRaw('1=0');
            }

            // level string (bukan enum object)
            $tlRoleVals = ['TL','TLL','TLF','TLR'];
            $staffRoleVals = ['AO','BE','FE','SO','RO','SA'];

            $tlIds = User::query()
                ->whereIn('id', $directIds)
                ->whereIn('level', $tlRoleVals)
                ->pluck('id')
                ->map(fn($v) => (int) $v)
                ->values()
                ->all();

            $directStaffIds = User::query()
                ->whereIn('id', $directIds)
                ->whereIn('level', $staffRoleVals)
                ->pluck('id')
                ->map(fn($v) => (int) $v)
                ->values()
                ->all();

            $staffFromTlIds = [];
            if (!empty($tlIds)) {
                $staffFromTlIds = OrgAssignment::query()
                    ->active()
                    ->whereIn('leader_id', $tlIds)
                    ->pluck('user_id')
                    ->map(fn($v) => (int) $v)
                    ->values()
                    ->all();

                // filter benar2 staff lapangan
                $staffFromTlIds = User::query()
                    ->whereIn('id', $staffFromTlIds)
                    ->whereIn('level', $staffRoleVals)
                    ->pluck('id')
                    ->map(fn($v) => (int) $v)
                    ->values()
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

    public function assessmentUpdater()
    {
        return $this->belongsTo(User::class, 'assessment_updated_by');
    }

}
