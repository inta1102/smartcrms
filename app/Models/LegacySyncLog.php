<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacySyncLog extends Model
{
    protected $table = 'legacy_sync_logs';

    protected $fillable = [
        'position_date','status','batch_id',
        'total_cases','synced_cases','skipped_cases','failed_cases',
        'message','run_by',
    ];

    protected $casts = [
        'position_date' => 'date',
    ];

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
