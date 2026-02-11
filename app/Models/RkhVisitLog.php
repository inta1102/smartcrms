<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RkhVisitLog extends Model
{
    protected $fillable = [
        'rkh_detail_id',
        'user_id',
        'visited_at',
        'latitude',
        'longitude',
        'location_note',
        'notes',
        'agreement',
        'next_action',
        'next_action_due',
        'photo_path',
        'promoted_at',
        'promoted_to_case_id',
        'promoted_action_id',
    ];

    protected $casts = [
        'visited_at' => 'datetime',
        'next_action_due' => 'date',
        'promoted_at' => 'datetime',
    ];

    public function detail(): BelongsTo
    {
        return $this->belongsTo(RkhDetail::class, 'rkh_detail_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
