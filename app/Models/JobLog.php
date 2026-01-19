<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobLog extends Model
{
    protected $table = 'job_logs';

    protected $fillable = [
        'job_key',
        'status',
        'duration_ms',
        'count',
        'message',
        'meta',
        'ran_at',
    ];

    protected $casts = [
        'meta'  => 'array',
        'ran_at'=> 'datetime',
    ];
}
