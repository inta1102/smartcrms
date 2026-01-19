<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegalCase extends Model
{
    protected $table = 'legal_cases';

    protected $fillable = [
        'npl_case_id',
        'legal_case_no',
        'status',
        'escalation_reason',
        'legal_owner_id',
        'recommended_action',
        'assessment_notes',
        'total_outstanding_snapshot',
        'total_collateral_value_snapshot',
        'closed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'total_outstanding_snapshot' => 'decimal:2',
        'total_collateral_value_snapshot' => 'decimal:2',
        'closed_at' => 'datetime',
    ];

    // === RELATIONS ===

    public function nplCase()
    {
        return $this->belongsTo(\App\Models\NplCase::class, 'npl_case_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'legal_owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(LegalAction::class, 'legal_case_id')->orderBy('sequence_no');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LegalDocument::class, 'legal_case_id');
    }

    public function costs(): HasMany
    {
        return $this->hasMany(LegalCost::class, 'legal_case_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LegalEvent::class, 'legal_case_id')->orderBy('event_at');
    }

    // === HELPERS (buat dashboard cepat) ===

    public function totalCost(): float
    {
        return (float) $this->costs()->sum('amount');
    }

    public function totalRecovery(): float
    {
        return (float) $this->actions()->sum('recovery_amount');
    }
    
}
