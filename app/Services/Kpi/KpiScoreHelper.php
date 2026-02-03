<?php

namespace App\Services\Kpi;

class KpiScoreHelper
{
    /**
     * Convert achievement percent (0..∞) into score 1..5 using standard band:
     * <25 => 1; 25-49 => 2; 50-74 => 3; 75-99 => 4; >=100 => 5
     */
    public static function scoreFromAchievementPct(float $pct): int
    {
        if ($pct < 25) return 1;
        if ($pct < 50) return 2;
        if ($pct < 75) return 3;
        if ($pct < 100) return 4;
        return 5;
    }

    /**
     * For repayment rate 0..100 (typical):
     * <90 =>1; 90-94 =>2; 95-97 =>3; 98-99 =>4; 100 =>5
     */
    public static function scoreFromRepaymentRate(float $rr): int
    {
        if ($rr < 90) return 1;
        if ($rr < 95) return 2;
        if ($rr < 98) return 3;
        if ($rr < 100) return 4;
        return 5;
    }

    /**
     * For NPL migration percent (lower is better)
     * 0 =>5; <=1 =>4; <=2 =>3; <=3 =>2; >3 =>1
     */
    public static function scoreFromNplMigration(float $pct): int
    {
        if ($pct <= 0) return 5;
        if ($pct <= 1) return 4;
        if ($pct <= 2) return 3;
        if ($pct <= 3) return 2;
        return 1;
    }

    public static function safePct(float $num, float $den): float
    {
        if ($den <= 0) return 0.0;
        return ($num / $den) * 100.0;
    }
}
