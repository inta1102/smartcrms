<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KpiRoTopupAdjBatch extends Model
{
    protected $table = 'kpi_ro_topup_adj_batches';

    protected $fillable = [
        'period_month',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'approved_as_of_date',
        'notes',
    ];

    protected $casts = [
        'period_month' => 'date',
        'approved_at' => 'datetime',
        'approved_as_of_date' => 'date',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(KpiRoTopupAdjLine::class, 'batch_id');
    }

    public function scopeDraft($q)
    {
        return $q->where('status', 'draft');
    }

    public function scopeApproved($q)
    {
        return $q->where('status', 'approved');
    }
}