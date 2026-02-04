<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiSoTarget extends Model
{
    protected $table = 'kpi_so_targets';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_TL = 'pending_tl';
    public const STATUS_PENDING_KASI = 'pending_kasi';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'period',
        'user_id',
        'ao_code',
        'target_os_disbursement',
        'target_noa_disbursement',
        'target_rr',
        'target_activity',
        'status',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'period' => 'date',
        'approved_at' => 'datetime',
        'target_rr' => 'decimal:2',
    ];
}
