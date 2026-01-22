<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShmCheckRequestLog extends Model
{
    protected $table = 'shm_check_request_logs';

    protected $fillable = [
        'request_id','actor_id','action','from_status','to_status','message','meta'
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ShmCheckRequest::class, 'request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
