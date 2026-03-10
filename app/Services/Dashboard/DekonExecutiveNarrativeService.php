<?php

namespace App\Services\Dashboard;

class DekonExecutiveNarrativeService
{
    public function generate($row, $prevRow = null): string
    {
        if (!$row) {
            return 'Data portofolio belum tersedia sehingga narasi eksekutif belum dapat dibentuk.';
        }

        $totalOs   = (float) ($row->total_os ?? 0);
        $nplPct    = (float) ($row->npl_pct ?? 0);
        $nplOs     = (float) ($row->npl_os ?? 0);
        $restrOs   = (float) ($row->restr_os ?? 0);
        $dpd12Os   = (float) ($row->dpd12_os ?? 0);
        $targetYtd = (float) ($row->target_ytd ?? 0);
        $actualYtd = (float) ($row->realisasi_ytd ?? 0);

        $parts = [];

        // 1. Portfolio movement
        if ($prevRow) {
            $prevOs = (float) ($prevRow->total_os ?? 0);
            if ($prevOs > 0) {
                $deltaOsPct = (($totalOs - $prevOs) / $prevOs) * 100;
                $parts[] = 'Secara umum, outstanding kredit berada pada posisi Rp ' . number_format($totalOs, 0, ',', '.') .
                    ' dengan pergerakan ' . ($deltaOsPct >= 0 ? 'naik' : 'turun') . ' ' .
                    number_format(abs($deltaOsPct), 2, ',', '.') . '% dibanding bulan sebelumnya.';
            } else {
                $parts[] = 'Secara umum, outstanding kredit berada pada posisi Rp ' . number_format($totalOs, 0, ',', '.') . '.';
            }
        } else {
            $parts[] = 'Secara umum, outstanding kredit berada pada posisi Rp ' . number_format($totalOs, 0, ',', '.') . '.';
        }

        // 2. NPL
        if ($nplPct > 10) {
            $parts[] = 'Kualitas portofolio masih berada dalam tekanan dengan rasio NPL sebesar ' .
                number_format($nplPct, 2, ',', '.') . '% atau senilai Rp ' . number_format($nplOs, 0, ',', '.') .
                ', jauh di atas threshold sehat.';
        } elseif ($nplPct > 5) {
            $parts[] = 'Rasio NPL tercatat sebesar ' . number_format($nplPct, 2, ',', '.') .
                '% atau senilai Rp ' . number_format($nplOs, 0, ',', '.') .
                ', sehingga tetap memerlukan pengendalian kualitas yang ketat.';
        } else {
            $parts[] = 'Rasio NPL berada pada level ' . number_format($nplPct, 2, ',', '.') .
                '%, yang menunjukkan kualitas portofolio relatif terjaga.';
        }

        // 3. Restr & aging
        if ($totalOs > 0 && $restrOs > 0) {
            $restrPct = ($restrOs / $totalOs) * 100;
            $parts[] = 'Portofolio restrukturisasi mencapai Rp ' . number_format($restrOs, 0, ',', '.') .
                ' atau sekitar ' . number_format($restrPct, 2, ',', '.') .
                '% dari total outstanding, sehingga perlu dimonitor terhadap risiko pemburukan lanjutan.';
        }

        if ($dpd12Os > 0) {
            $parts[] = 'Selain itu, eksposur kredit dengan aging lebih dari 12 bulan masih sebesar Rp ' .
                number_format($dpd12Os, 0, ',', '.') .
                ', yang mengindikasikan perlunya fokus recovery pada bucket berisiko tinggi.';
        }

        // 4. Target
        if ($targetYtd > 0) {
            $ach = ($actualYtd / $targetYtd) * 100;
            $parts[] = 'Dari sisi pencapaian bisnis, realisasi YTD tercatat Rp ' . number_format($actualYtd, 0, ',', '.') .
                ' dibanding target Rp ' . number_format($targetYtd, 0, ',', '.') .
                ', atau setara ' . number_format($ach, 2, ',', '.') . '% dari target.';
        } else {
            $parts[] = 'Dari sisi pencapaian bisnis, target YTD belum tersedia sehingga evaluasi gap target dan aktual belum dapat dilakukan secara memadai.';
        }

        return implode(' ', $parts);
    }
}