<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalCost extends Model
{
    protected $table = 'legal_costs';

    protected $fillable = [
        'legal_case_id',
        'legal_action_id',
        'cost_type',
        'amount',
        'cost_date',
        'description',
        'paid_by',
        'evidence_doc_id',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'cost_date' => 'date',
    ];

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'legal_case_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(LegalAction::class, 'legal_action_id');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'evidence_doc_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
