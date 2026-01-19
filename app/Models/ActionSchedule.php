<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ActionSchedule extends Model
{
    use HasFactory;

    // Pilih salah satu: fillable saja (lebih aman)
    protected $fillable = [
        'npl_case_id',
        'schedulable_type',
        'schedulable_id',

        'type',
        'level',
        'rule_version',

        'title',
        'notes',

        'scheduled_at',
        'status',
        'completed_at',
        'last_notified_at',

        'escalated_at',
        'escalation_note',
        'escalated_from_id',
        'escalated_to_id',

        'created_by',
        'assigned_to',

        'source_system',
        'source_ref_id',
    ];

    protected $casts = [
        'scheduled_at'     => 'datetime',
        'completed_at'     => 'datetime',
        'last_notified_at' => 'datetime',
        'escalated_at'     => 'datetime',
    ];

    // ======================
    // Relationships
    // ======================

    // App\Models\ActionSchedule.php
    public function nplCase()
    {
        return $this->belongsTo(\App\Models\NplCase::class, 'npl_case_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function schedulable()
    {
        return $this->morphTo();
    }

    // Self reference: eskalasi
    public function escalatedFrom()
    {
        return $this->belongsTo(self::class, 'escalated_from_id');
    }

    public function escalatedTo()
    {
        return $this->belongsTo(self::class, 'escalated_to_id');
    }

    // ======================
    // Scopes
    // ======================
    public function scopePending($q)
    {
        return $q->where('status', 'pending');
    }

    public function scopeOpen($q)
    {
        return $q->whereIn('status', ['pending', 'escalated']); // kalau mau monitor yg belum done
    }

    public function scopeDone($q)
    {
        return $q->where('status', 'done');
    }

    
}
