<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemHeartbeat extends Model
{
    protected $fillable = ['component', 'status', 'meta', 'beat_at'];

    protected $casts = [
        'meta' => 'array',
        'beat_at' => 'datetime',
    ];
}
