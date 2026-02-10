<?php

namespace App\Models\Kpi;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiSoCommunityInput extends Model
{
    protected $table = 'kpi_so_community_inputs';

    protected $fillable = [
        'period',
        'user_id',
        'handling_actual',
        'os_adjustment',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'period' => 'date',
        'handling_actual' => 'integer',
        'os_adjustment' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
