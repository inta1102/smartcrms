<?php

namespace App\Models\Legal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalActionHtDocument extends Model
{
    protected $table = 'legal_action_ht_documents';

    public const STATUS_DRAFT    = 'draft';
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'legal_action_id',
        'doc_type',
        'doc_no',
        'doc_date',
        'issued_by',
        'file_path',
        'remarks',
        'is_required',
        'status',
        'verified_by',
        'verified_at',
        'verify_notes',
    ];

    protected $casts = [
        'doc_date' => 'date',
        'is_required' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function legalAction(): BelongsTo
    {
        return $this->belongsTo(\App\Models\LegalAction::class, 'legal_action_id');
    }
}
