<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiBeTarget extends Model
{
    protected $table = 'kpi_be_targets';

    protected $fillable = [
        'period',
        'be_user_id',
        'target_os_selesai',
        'target_noa_selesai',
        'target_bunga_masuk',
        'target_denda_masuk',
    ];

    protected $casts = [
        'period' => 'date',
        'target_os_selesai' => 'decimal:2',
        'target_bunga_masuk' => 'decimal:2',
        'target_denda_masuk' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'be_user_id');
    }
}
