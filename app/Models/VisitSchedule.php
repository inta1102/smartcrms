<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitSchedule extends Model
{
    protected $table = 'visit_schedules';

    protected $fillable = [
        'rkh_detail_id',
        'scheduled_at',
        'title',
        'notes',
        'status',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at'   => 'datetime',
        'ended_at'     => 'datetime',
    ];

    public function rkhDetail(): BelongsTo
    {
        return $this->belongsTo(RkhDetail::class, 'rkh_detail_id');
    }
}
