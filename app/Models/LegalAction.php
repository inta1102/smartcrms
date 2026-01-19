<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Builder;


class LegalAction extends Model
{
    protected $table = 'legal_actions';
    

    protected $fillable = [
        'legal_case_id',
        'action_type',
        'sequence_no',
        'status',
        'start_at',
        'end_at',
        'external_ref_no',
        'external_institution',
        'handler_type',
        'law_firm_name',
        'handler_name',
        'handler_phone',
        'summary',
        'notes',
        'result_type',
        'recovery_amount',
        'recovery_date',
        'meta',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'recovery_date' => 'date',
        'recovery_amount' => 'decimal:2',
        'meta' => 'array',
        'closed_at' => 'datetime',
    ];

    // === RELATIONS ===

    // Scope biar query HT konsisten
    public function scopeHt(Builder $q): Builder
    {
        return $q->where('action_type', self::TYPE_HT_EXECUTION);
    }
    
    public function legalCase()
    {
        return $this->belongsTo(\App\Models\LegalCase::class, 'legal_case_id');
    }


    public function statusLogs(): HasMany
    {
        return $this->hasMany(LegalActionStatusLog::class, 'legal_action_id')
            ->orderByDesc('changed_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LegalDocument::class, 'legal_action_id')
            ->latest(); // atau ->orderByDesc('created_at')
    }

    public function costs(): HasMany
    {
        return $this->hasMany(LegalCost::class, 'legal_action_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LegalEvent::class, 'legal_action_id')->orderBy('event_at');
    }


    public function shipment(): HasOne
    {
        return $this->hasOne(LegalActionShipment::class, 'legal_action_id');
    }

    public const STATUS_DRAFT      = 'draft';
    public const STATUS_SUBMITTED  = 'submitted';
    public const STATUS_IN_PROGRESS= 'in_progress';
    public const STATUS_WAITING    = 'waiting';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_CANCELLED  = 'cancelled';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_PREPARED   = 'prepared';
    public const STATUS_EXECUTED   = 'executed';
    public const STATUS_SETTLED    = 'settled';
    public const STATUS_CLOSED     = 'closed';
    public const STATUS_SCHEDULED = 'scheduled';


    public const TYPE_SOMASI          = 'somasi';
    public const TYPE_HT_EXECUTION    = 'ht_execution';
    public const TYPE_FIDUSIA_EXEC    = 'fidusia_execution';
    public const TYPE_CIVIL_LAWSUIT   = 'civil_lawsuit';
    public const TYPE_PKPU_BANKRUPTCY = 'pkpu_bankruptcy';
    public const TYPE_CRIMINAL_REPORT = 'criminal_report';

    public function latestStatusLog()
    {
        return $this->hasOne(\App\Models\LegalActionStatusLog::class, 'legal_action_id')
            ->latestOfMany('changed_at');
    }

    protected $attributes = [
        'meta' => '[]',
    ];

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT       => 'DRAFT',
            self::STATUS_PREPARED    => 'PREPARED',
            self::STATUS_SUBMITTED   => 'SUBMITTED',
            self::STATUS_SCHEDULED   => 'SCHEDULED',
            self::STATUS_EXECUTED    => 'EXECUTED',
            self::STATUS_CLOSED      => 'CLOSED',
            self::STATUS_CANCELLED   => 'CANCELLED',
            default => strtoupper((string) $this->status),
        };
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_SUBMITTED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_WAITING,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_FAILED,
            self::STATUS_SCHEDULED,
            self::STATUS_PREPARED,
            self::STATUS_EXECUTED,
            self::STATUS_CLOSED,

        ];
    }

    public static function resultTypes(): array
    {
        return ['paid','partial','reject','no_response'];
    }

    public function schedules()
    {
        return $this->morphMany(\App\Models\ActionSchedule::class, 'schedulable');
    }

    public function htExecution(): HasOne
    {
        return $this->hasOne(\App\Models\Legal\LegalActionHtExecution::class, 'legal_action_id');
    }

    public function htDocuments(): HasMany
    {
        return $this->hasMany(\App\Models\Legal\LegalActionHtDocument::class, 'legal_action_id');
    }

    public function htEvents(): HasMany
    {
        return $this->hasMany(\App\Models\Legal\LegalActionHtEvent::class, 'legal_action_id')->latest('event_at');
    }

    public function htAuctions(): HasMany
    {
        return $this->hasMany(\App\Models\Legal\LegalActionHtAuction::class, 'legal_action_id')->orderBy('attempt_no');
    }

    public function htUnderhandSale(): HasOne
    {
        return $this->hasOne(\App\Models\Legal\LegalActionHtUnderhandSale::class, 'legal_action_id');
    }

    public function htAuction()
    {
        return $this->hasOne(\App\Models\Legal\LegalActionHtAuction::class, 'legal_action_id');
    }

    public function getHtMetaAttribute(): array
    {
        $m = is_array($this->meta) ? $this->meta : [];
        return $m['ht'] ?? $m['ht_execution'] ?? $m;
    }

    public function getNplCaseAttribute()
    {
        return $this->legalCase?->nplCase;
    }

    public function adminChecklists(): HasMany
    {
        return $this->hasMany(\App\Models\LegalAdminChecklist::class, 'legal_action_id')
            ->orderBy('sort_order');
    }

   
}
