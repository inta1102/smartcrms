<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiRoTopupAdjLine extends Model
{
    protected $table = 'kpi_ro_topup_adj_lines';

    protected $fillable = [
        'batch_id',
        'period_month',
        'cif',
        'source_ao_code',
        'target_ao_code',
        'amount_frozen',
        'calc_as_of_date',
        'calc_meta',
        'reason',
    ];

    protected $casts = [
        'period_month' => 'date',
        'amount_frozen' => 'float',
        'calc_as_of_date' => 'date',
        'calc_meta' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(KpiRoTopupAdjBatch::class, 'batch_id');
    }

    public function getSourceAoCodeAttribute($v): ?string
    {
        return $v ? self::padAo($v) : null;
    }

    public function getTargetAoCodeAttribute($v): string
    {
        return self::padAo($v);
    }

    public static function padAo(?string $ao): string
    {
        $ao = trim((string)($ao ?? ''));
        return str_pad($ao, 6, '0', STR_PAD_LEFT);
    }
}