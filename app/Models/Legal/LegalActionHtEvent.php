<?php

namespace App\Models\Legal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalActionHtEvent extends Model
{
    protected $table = 'legal_action_ht_events';

    protected $fillable = [
        'legal_action_id',
        'event_type',
        'event_at',
        'ref_no',
        'payload',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'event_at' => 'datetime',
        'payload' => 'array',
    ];

    public function legalAction(): BelongsTo
    {
        return $this->belongsTo(\App\Models\LegalAction::class, 'legal_action_id');
    }
}
