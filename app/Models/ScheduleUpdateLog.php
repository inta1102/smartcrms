<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleUpdateLog extends Model
{
    protected $table = 'schedule_update_logs';

    protected $fillable = [
        'position_date','status','batch_id',
        'total_cases','scheduled_cases','cancelled_cases','failed_cases',
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
