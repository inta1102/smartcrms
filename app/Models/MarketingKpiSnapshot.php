<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingKpiSnapshot extends Model
{
    protected $table = 'marketing_kpi_snapshots';

    protected $fillable = [
        'period',
        'user_id',
        'os_opening',
        'os_closing',
        'os_growth',
        'noa_new',
        'noa_total',
        'snapshot_at',
        'source',
    ];

    protected $casts = [
        'period'     => 'date:Y-m-d',
        'os_opening' => 'decimal:2',
        'os_closing' => 'decimal:2',
        'os_growth'  => 'decimal:2',
        'noa_new'    => 'integer',
        'noa_total'  => 'integer',
        'snapshot_at'=> 'datetime',
    ];

    // ===== Relations =====
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ===== Scopes =====
    public function scopeForPeriod(Builder $q, string $periodYmd): Builder
    {
        return $q->whereDate('period', $periodYmd);
    }
}
