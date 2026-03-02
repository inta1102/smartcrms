<?php

namespace App\Models\Kpi;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class KpiKslrMonthly extends Model
{
    protected $table = 'kpi_kslr_monthlies';

    protected $fillable = [
        'period',
        'kslr_id',
        'calc_mode',

        'kyd_ach_pct',
        'dpk_mig_pct',
        'rr_pct',
        'community_pct',

        'score_kyd',
        'score_dpk',
        'score_rr',
        'score_com',

        'total_score_weighted',
        'meta',
    ];

    protected $casts = [
        'period' => 'date',
        'kyd_ach_pct' => 'float',
        'dpk_mig_pct' => 'float',
        'rr_pct' => 'float',
        'community_pct' => 'float',

        'total_score_weighted' => 'float',

        'meta' => 'array',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATION
    |--------------------------------------------------------------------------
    */

    public function kslr()
    {
        return $this->belongsTo(\App\Models\User::class, 'kslr_id');
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeForPeriod(Builder $q, string $periodYmd): Builder
    {
        return $q->whereDate('period', Carbon::parse($periodYmd)->startOfMonth());
    }

    public function scopeForMode(Builder $q, string $mode): Builder
    {
        return $q->where('calc_mode', $mode);
    }

    public function scopeForKslr(Builder $q, int $kslrId): Builder
    {
        return $q->where('kslr_id', $kslrId);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER
    |--------------------------------------------------------------------------
    */

    public function getStatusLabelAttribute(): string
    {
        $score = (float) $this->total_score_weighted;

        if ($score >= 4.5) return 'Excellent';
        if ($score >= 3.5) return 'On Track';
        if ($score >= 2.5) return 'Warning';
        return 'Critical';
    }
}