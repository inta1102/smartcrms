<?php

namespace App\Services\Kpi\Concerns;

trait Score6
{
    /**
     * Score 1..6 dari pencapaian % (ach).
     * Default rule (sesuai SO OS Realisasi):
     * 0-24 => 1
     * 25-49 => 2
     * 50-74 => 3
     * 75-99 => 4
     * 100   => 5
     * >100  => 6
     */
    protected function scoreByAchPct(?float $achPct): int
    {
        if ($achPct === null) return 1;
        if ($achPct < 25) return 1;
        if ($achPct < 50) return 2;
        if ($achPct < 75) return 3;
        if ($achPct < 100) return 4;

        // 100 exactly (atau sangat dekat)
        if ($achPct <= 100.0000001) return 5;
        return 6;
    }

    /**
     * Score 1..6 dari NOA (contoh SO: 1,2,3,4,5,>5).
     */
    protected function scoreByNoa6(int $n): int
    {
        if ($n <= 1) return 1;
        if ($n === 2) return 2;
        if ($n === 3) return 3;
        if ($n === 4) return 4;
        if ($n === 5) return 5;
        return 6; // > 5
    }

    /**
     * Score 1..6 dari angka activity komunitas (0, -, -, 1, 2, >2).
     */
    protected function scoreByCommunity6(int $n): int
    {
        if ($n <= 0) return 1;
        if ($n === 1) return 4;
        if ($n === 2) return 5;
        return 6; // >2
    }

    /**
     * Score 1..6 dari RR% (AO: <70, 70-79.9, 80-89.9, 90-97.5, 97.5-99.9, 100)
     */
    protected function scoreByRrPct6(?float $rrPct): int
    {
        if ($rrPct === null) return 1;
        if ($rrPct < 70) return 1;
        if ($rrPct < 80) return 2;
        if ($rrPct < 90) return 3;
        if ($rrPct < 97.5) return 4;
        if ($rrPct < 100) return 5;
        return 6; // 100
    }
}
