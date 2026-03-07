<?php

namespace App\Models\Kpi;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class KpiOsDailyAo extends Model
{
    protected $table = 'kpi_os_daily_aos';

    protected $fillable = [
        'position_date',
        'ao_code',

        'os_total',
        'os_l0',
        'os_lt',
        'os_dpk',
        'os_kl',
        'os_d',
        'os_m',
        'os_potensi',

        'noa_total',
        'noa_l0',
        'noa_lt',
        'noa_dpk',
        'noa_kl',
        'noa_d',
        'noa_m',
        'noa_potensi',

        'source',
        'computed_at',
    ];

    protected $casts = [
        'position_date' => 'date',
        'computed_at'   => 'datetime',

        'os_total'   => 'decimal:2',
        'os_l0'      => 'decimal:2',
        'os_lt'      => 'decimal:2',
        'os_dpk'     => 'decimal:2',
        'os_kl'      => 'decimal:2',
        'os_d'       => 'decimal:2',
        'os_m'       => 'decimal:2',
        'os_potensi' => 'decimal:2',

        'noa_total'   => 'integer',
        'noa_l0'      => 'integer',
        'noa_lt'      => 'integer',
        'noa_dpk'     => 'integer',
        'noa_kl'      => 'integer',
        'noa_d'       => 'integer',
        'noa_m'       => 'integer',
        'noa_potensi' => 'integer',
    ];

    public function scopeForAo(Builder $query, string $aoCode): Builder
    {
        return $query->where('ao_code', $aoCode);
    }

    public function scopeBetweenDates(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('position_date', [$from, $to]);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position_date');
    }
}