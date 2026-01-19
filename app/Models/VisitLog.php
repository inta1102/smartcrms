<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'visited_at' => 'datetime',
    ];

    public function nplCase()
    {
        return $this->belongsTo(NplCase::class);
    }

    public function schedule()
    {
        return $this->belongsTo(ActionSchedule::class, 'action_schedule_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
