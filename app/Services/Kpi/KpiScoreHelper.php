<?php

namespace App\Services\Kpi;

class KpiScoreHelper
{
    // ==========================================================
    // UTIL
    // ==========================================================
    public static function safePct(float $num, float $den): float
    {
        if ($den <= 0) return 0.0;
        return ($num / $den) * 100.0;
    }

    // ==========================================================
    // LEGACY (1..5) — jangan diubah (BE lama masih pakai ini)
    // ==========================================================
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
     * Legacy repayment (1..5)
     * <70 =>1; 70-79 =>2; 80-89 =>3; 90-99 =>4; 100 =>5
     */
    public static function scoreFromRepaymentRate(float $rr): int
    {
        if ($rr < 70) return 1;
        if ($rr < 80) return 2;
        if ($rr < 90) return 3;
        if ($rr < 100) return 4;
        return 5;
    }

    /**
     * For NPL migration percent (lower is better) — legacy
     * <1 =>5; <=2 =>4; <=3 =>3; <=4 =>2; >4 =>1
     */
    public static function scoreFromNplMigration(float $pct): int
    {
        if ($pct < 1) return 5;
        if ($pct <= 2) return 4;
        if ($pct <= 3) return 3;
        if ($pct <= 4) return 2;
        return 1;
    }

    // ==========================================================
    // NEW (1..6) — sesuai rubrik SO/AO yang user minta
    // ==========================================================

    /**
     * Generic achievement % => score 1..6
     * 0-24 =>1; 25-49 =>2; 50-74 =>3; 75-99 =>4; 100 =>5; >100 =>6
     */
    public static function scoreFromAchievementPct6(float $pct): int
    {
        if ($pct < 25) return 1;
        if ($pct < 50) return 2;
        if ($pct < 75) return 3;
        if ($pct < 100) return 4;
        if ($pct == 100.0) return 5;
        return 6;
    }

    /**
     * Repayment Rate (SO rubric):
     * <70 =>1; 70-79.9 =>2; 80-89.9 =>3; 90-94.9 =>4; 95-99.9 =>5; 100 =>6
     */
    public static function scoreFromRepaymentRateSo6(float $rr): int
    {
        if ($rr < 70) return 1;
        if ($rr < 80) return 2;
        if ($rr < 90) return 3;
        if ($rr < 95) return 4;
        if ($rr < 100) return 5;
        return 6;
    }

    /**
     * Repayment Rate (AO rubric) — sesuai gambar:
     * <70 =>1; 70-79.9 =>2; 80-89.9 =>3; 90-97.5 =>4; 97.5-99.9 =>5; 100 =>6
     */
    public static function scoreFromRepaymentRateAo6(float $rr): int
    {
        if ($rr < 70) return 1;
        if ($rr < 80) return 2;
        if ($rr < 90) return 3;
        if ($rr < 97.5) return 4;
        if ($rr < 100) return 5;
        return 6;
    }

        /**
     * TLUM NOA rubric (sesuai slide TL UMKM):
     * 1-6 =>1, 7-12=>2, 13-18=>3, 19-24=>4, 25-30=>5, >30=>6
     */
    public static function scoreFromTlumNoaGrowth6(int $noa): int
    {
        if ($noa <= 6) return 1;
        if ($noa <= 12) return 2;
        if ($noa <= 18) return 3;
        if ($noa <= 24) return 4;
        if ($noa <= 30) return 5;
        return 6;
    }

    /**
     * TLUM Community rubric (sesuai slide TL UMKM):
     * 1=>1, 2=>2, 3=>3, 4=>4, 5=>5, >5=>6
     */
    public static function scoreFromTlumCommunity6(int $n): int
    {
        if ($n <= 1) return 1;
        if ($n === 2) return 2;
        if ($n === 3) return 3;
        if ($n === 4) return 4;
        if ($n === 5) return 5;
        return 6;
    }

    /**
     * NOA (SO rubric):
     * 1=>1, 2=>2, 3=>3, 4=>4, 5=>5, >5=>6
     */
    public static function scoreFromSoNoa6(int $noa): int
    {
        if ($noa <= 1) return 1;
        if ($noa === 2) return 2;
        if ($noa === 3) return 3;
        if ($noa === 4) return 4;
        if ($noa === 5) return 5;
        return 6;
    }

    /**
     * Handling Komunitas (SO rubric):
     * 0=>1, 1=>4, 2=>5, >=3=>6
     * (karena kolom 2 & 3 di rubrik memang "-" / tidak dipakai)
     */
    public static function scoreFromHandlingKomunitasSo6(int $n): int
    {
        if ($n <= 0) return 1;
        if ($n === 1) return 4;
        if ($n === 2) return 5;
        return 6;
    }

    /**
     * Pertumbuhan NOA (AO rubric):
     * <4=>1; 4-6=>2; 7-9=>3; 10-12=>4; 13-15=>5; >15=>6
     */
    public static function scoreFromAoNoaGrowth6(int $growth): int
    {
        if ($growth < 4) return 1;
        if ($growth <= 6) return 2;
        if ($growth <= 9) return 3;
        if ($growth <= 12) return 4;
        if ($growth <= 15) return 5;
        return 6;
    }

    /**
     * Grab to Community (AO rubric):
     * 0=>1, 1=>4, 2=>5, >=3=>6
     */
    public static function scoreFromAoCommunity6(int $n): int
    {
        if ($n <= 0) return 1;
        if ($n === 1) return 4;
        if ($n === 2) return 5;
        return 6;
    }

    /**
     * Daily Report / Kunjungan (AO rubric):
     * 1=>1, 2=>2, 3=>3, 4=>4, 5=>5, >5=>6
     */
    public static function scoreFromAoDailyReport6(int $n): int
    {
        if ($n <= 1) return 1;
        if ($n === 2) return 2;
        if ($n === 3) return 3;
        if ($n === 4) return 4;
        if ($n === 5) return 5;
        return 6;
    }

    public static function scoreFromAoOsRealisasiPct6(float $pct): int
    {
        // AO OS Realisasi rubric: <70, 70-79, 80-89, 90-99, 100, >100
        if ($pct < 70) return 1;
        if ($pct < 80) return 2;
        if ($pct < 90) return 3;
        if ($pct < 100) return 4;
        if ($pct == 100.0) return 5;
        return 6;
    }

    /**
     * Achievement percent for positive KPI (bigger is better).
     * If target <= 0 => achievement = 0 (prevent free score).
     */
    public static function achievementPct(float $actual, float $target): float
    {
        if ($target <= 0) return 0.0;
        if ($actual <= 0) return 0.0;
        return ($actual / $target) * 100.0;
    }

    /**
     * Score band 1..6 based on achievement percent.
     * <25 => 1; 25-49 => 2; 50-74 => 3; 75-99 => 4; =100 => 5; >100 => 6
     */
    public static function scoreBand1to6(float $achPct): int
    {
        if ($achPct < 25) return 1;
        if ($achPct < 50) return 2;
        if ($achPct < 75) return 3;
        if ($achPct < 100) return 4;
        if (abs($achPct - 100) < 1e-9) return 5;
        return 6;
    }

    /**
     * Utility: safe round for UI.
     */
    public static function roundPct(float $pct, int $decimals = 2): float
    {
        return round($pct, $decimals);
    }

    /**
     * ==========================================================
     * RR ACTUAL (Semakin besar semakin baik)
     * Input: percent 0 – 100
     * Output: score 1 – 6
     * ==========================================================
     */
    public static function scoreBand1to6ByActualRr(float $rrPct): float
    {
        $rrPct = max(0, min($rrPct, 100)); // clamp 0–100

        if ($rrPct >= 95) return 6;
        if ($rrPct >= 90) return 5;
        if ($rrPct >= 85) return 4;
        if ($rrPct >= 80) return 3;
        if ($rrPct >= 70) return 2;
        return 1;
    }

    /**
     * ==========================================================
     * DPK ACTUAL (Reverse Metric)
     * Semakin kecil semakin baik
     * Input: percent 0 – 100
     * Output: score 1 – 6
     * ==========================================================
     */
    public static function scoreBand1to6ByActualDpkReverse(float $dpkPct): float
    {
        $dpkPct = max(0, $dpkPct); // no negative

        if ($dpkPct <= 1) return 6;
        if ($dpkPct <= 2) return 5;
        if ($dpkPct <= 3) return 4;
        if ($dpkPct <= 5) return 3;
        if ($dpkPct <= 8) return 2;
        return 1;
    }

    /**
     * ==========================================================
     * Optional: Utility clamp percent
     * ==========================================================
     */
    public static function clampPercent(float $value): float
    {
        return max(0, min($value, 100));
    }
}
