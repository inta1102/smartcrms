<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;


class AoAgenda extends Model
{
    use SoftDeletes;

    protected $table = 'ao_agendas';

    protected $fillable = [
        'title',
        'notes',

        'npl_case_id',
        'resolution_target_id',
        'ao_id',

        'agenda_type',
        'planned_at',
        'due_at',

        'started_at',
        'started_by',

        'completed_at',
        'completed_by',

        'status',
        'result_summary',
        'result_detail',

        'evidence_required',
        'evidence_path',
        'evidence_notes',

        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'planned_at'        => 'datetime',
        'due_at'            => 'datetime',
        'started_at'        => 'datetime',
        'completed_at'      => 'datetime',
        'evidence_required' => 'boolean',
    ];

    public const STATUS_PLANNED     = 'planned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE        = 'done';
    public const STATUS_OVERDUE     = 'overdue';
    public const STATUS_CANCELLED   = 'cancelled';

    // =========================
    // RELATIONS
    // =========================

    /**
     * ✅ Relasi utama (pakai ini sebagai standar)
     */
    public function nplCase()
    {
        return $this->belongsTo(NplCase::class, 'npl_case_id');
    }

    /**
     * ✅ Alias untuk backward compatibility
     * Kalau masih ada kode lama: $agenda->case->...
     */
    public function case()
    {
        return $this->nplCase();
    }

    public function resolutionTarget()
    {
        return $this->belongsTo(CaseResolutionTarget::class, 'resolution_target_id');
    }

    public function actions()
    {
        return $this->hasMany(CaseAction::class, 'ao_agenda_id');
    }

    /**
     * ✅ OPTIONAL: relasi langsung ke loan account via npl_cases
     * Ini valid untuk dipakai: with('loanAccount') / whereHas('loanAccount')
     *
     * Join:
     * ao_agendas.npl_case_id = npl_cases.id
     * npl_cases.loan_account_id = loan_accounts.id
     */
    public function loanAccount()
    {
        return $this->hasOneThrough(
            LoanAccount::class,
            NplCase::class,
            'id',              // firstKey on npl_cases (join ke ao_agendas.npl_case_id)
            'id',              // secondKey on loan_accounts (join ke npl_cases.loan_account_id)
            'npl_case_id',     // localKey on ao_agendas
            'loan_account_id'  // secondLocalKey on npl_cases
        );
    }

    // =========================
    // SCOPES
    // =========================

    public function scopeOpen($q)
    {
        return $q->whereIn('status', [
            self::STATUS_PLANNED,
            self::STATUS_OVERDUE,
            self::STATUS_IN_PROGRESS
        ]);
    }

    public function scopeDueSoon($q, int $days = 3)
    {
        return $q->whereNotNull('due_at')
            ->whereBetween('due_at', [now(), now()->addDays($days)]);
    }

    public function scopeOverdue($q)
    {
        return $q->where('status', self::STATUS_OVERDUE);
    }

    /**
     * ✅ Scope untuk membatasi agenda field-staff (AO/SO/FE/BE) by PIC
     * Jadi controller/sidebar tinggal panggil ->visibleTo(auth()->user())
     */
    public function scopeVisibleTo($q, User $user)
    {
        $level = strtolower(trim($user->roleValue() ?? ''));

        if (in_array($level, ['ao','so','fe','be'], true)) {
            $q->whereHas('nplCase', fn ($cq) => $cq->where('pic_user_id', $user->id));
        }

        return $q;
    }

    public function latestAction(): HasOne
    {
        // "action terbaru" berdasarkan created_at paling akhir
        return $this->hasOne(CaseAction::class, 'ao_agenda_id')->latestOfMany();
    }
}
