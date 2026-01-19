<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalActionStatusLog extends Model
{
    protected $table = 'legal_action_status_logs';

    protected $fillable = [
        'legal_action_id',
        'from_status',
        'to_status',
        'changed_at',
        'changed_by',
        'remarks',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function action(): BelongsTo
    {
        return $this->belongsTo(LegalAction::class, 'legal_action_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
