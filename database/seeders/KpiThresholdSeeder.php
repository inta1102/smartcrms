<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KpiThresholdSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('kpi_thresholds')->updateOrInsert(
            ['metric' => 'rr_pct'],
            [
                'title' => 'RR (%)',
                'direction' => 'higher_is_better',
                'green_min' => 90.00,
                'yellow_min' => 80.00,
                'red_min' => null,
                'is_active' => true,
                'updated_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
