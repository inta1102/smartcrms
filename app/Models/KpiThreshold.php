<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiThreshold extends Model
{
    protected $table = 'kpi_thresholds';

    protected $fillable = [
        'metric','title','direction',
        'green_min','yellow_min','red_min',
        'is_active','updated_by',
    ];

    protected $casts = [
        'green_min' => 'float',
        'yellow_min' => 'float',
        'red_min' => 'float',
        'is_active' => 'bool',
    ];

    public static function for(string $metric): ?self
    {
        return static::query()
            ->where('metric', $metric)
            ->where('is_active', true)
            ->first();
    }

    public function badge(float $value): array
    {
        // default fallback
        $green = $this->green_min ?? 90.0;
        $yellow = $this->yellow_min ?? 80.0;

        if ($this->direction === 'lower_is_better') {
            // kalau metrik makin kecil makin bagus (misal NPL%)
            if ($value <= $green) return ['label' => 'AMAN', 'level' => 'green'];
            if ($value <= $yellow) return ['label' => 'WASPADA', 'level' => 'yellow'];
            return ['label' => 'RISIKO', 'level' => 'red'];
        }

        // higher_is_better (RR)
        if ($value >= $green) return ['label' => 'AMAN', 'level' => 'green'];
        if ($value >= $yellow) return ['label' => 'WASPADA', 'level' => 'yellow'];
        return ['label' => 'RISIKO', 'level' => 'red'];
    }
}
