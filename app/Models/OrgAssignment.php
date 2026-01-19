<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class OrgAssignment extends Model
{
    protected $table = 'org_assignments';

    protected $fillable = [
        'user_id',
        'leader_id',
        'leader_role',
        'unit_code',
        'effective_from',
        'effective_to',
        'is_active',
        'active_key',
        'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'is_active'      => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function leader()
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', 1)
            ->where(function ($qq) {
                $qq->whereNull('effective_to')
                   ->orWhere('effective_to', '>=', now()->toDateString());
            })
            ->where('effective_from', '<=', now()->toDateString());
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
