<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalEvent extends Model
{
    protected $table = 'legal_events';

    protected $fillable = [
        'legal_case_id',
        'legal_action_id',
        'event_type',
        'title',
        'event_at',
        'location',
        'notes',
        'status',
        'remind_at',
        'remind_channels',
        'created_by',
    ];

    protected $casts = [
        'event_at' => 'datetime',
        'remind_at' => 'datetime',
        'reminded_at' => 'datetime',
        'remind_channels' => 'array',
    ];
    
    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'legal_case_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(LegalAction::class, 'legal_action_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
