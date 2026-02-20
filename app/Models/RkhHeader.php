<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RkhHeader extends Model
{
    protected $table = 'rkh_headers';

    protected $fillable = [
        'user_id',
        'tanggal',
        'total_jam',
        'status',
        'approved_by',
        'approved_at',
        'approval_note',
    ];

    protected $casts = [
        'tanggal'     => 'date',
        'approved_at' => 'datetime',
        'total_jam'   => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function details(): HasMany
    {
        return $this->hasMany(RkhDetail::class, 'rkh_id')->orderBy('jam_mulai');
    }

    public function scopeForUser($q, int $userId)
    {
        return $q->where('user_id', $userId);
    }

    public function scopeOnDate($q, string $ymd)
    {
        return $q->whereDate('tanggal', $ymd);
    }

    public function rejector()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
