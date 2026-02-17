<?php

namespace App\Services\Kpi;

class FeKpiInterpretationService
{
    /**
     * Build bullets + badges based on FE monthly row.
     * Assumsi row adalah stdClass dari query DB (kpi_fe_monthlies join targets).
     */
    public function build(object $row): array
    {
        $bullets = [];

        // ========= Helpers =========
        $pct = fn($v) => (float)($v ?? 0);

        $badgeFromAch = function (float $ach): array {
            // Generic ach based status
            if ($ach >= 100) return ['label' => 'On Track', 'class' => 'bg-emerald-100 text-emerald-800 border-emerald-200'];
            if ($ach >= 75)  return ['label' => 'Warning',  'class' => 'bg-amber-100 text-amber-800 border-amber-200'];
            return ['label' => 'Critical', 'class' => 'bg-rose-100 text-rose-800 border-rose-200'];
        };

        // ========= 1) OS TURUN =========
        $achOs = $pct($row->ach_os_turun_pct);
        $osBadge = $badgeFromAch($achOs);

        if ($achOs >= 100) {
            $bullets[] = "OS turun on track (≥ target). Pertahankan intensitas penagihan & fokus closing penyelesaian kasus prioritas.";
        } elseif ($achOs >= 75) {
            $bullets[] = "OS turun mendekati target. Dorong percepatan closing akun besar (top 10 OS) agar finish di akhir periode.";
        } else {
            $bullets[] = "OS turun masih di bawah target. Dorong recovery pokok & strategi penyelesaian (restruktur selektif / eksekusi agunan jika perlu).";
        }

        // ========= 2) MIGRASI NPL =========
        $achMig = $pct($row->ach_migrasi_pct);
        $migBadge = $badgeFromAch($achMig);

        if ($achMig >= 100) {
            $bullets[] = "Migrasi kolek on track. Pertahankan quality visit & follow-up agar akun borderline naik kolek stabil.";
        } elseif ($achMig >= 75) {
            $bullets[] = "Migrasi kolek mendekati target. Fokus akun borderline untuk naik kolek (3→2, 2→1) lewat jadwal visit & komitmen bayar.";
        } else {
            $bullets[] = "Migrasi kolek belum tercapai. Petakan akun borderline + buat rencana tindakan mingguan (visit, renegosiasi, eskalasi).";
        }

        // ========= 3) PENALTY PAID (REVENUE) =========
        $achPen = $pct($row->ach_penalty_pct);
        $penBadge = $badgeFromAch($achPen);

        // Ini yang dibalik: penalty besar = bagus
        if ($achPen >= 100) {
            $bullets[] = "Pendapatan Denda melampaui target. Recovery berkualitas & kontribusi pendapatan berjalan baik.";
        } elseif ($achPen >= 75) {
            $bullets[] = "Pendapatan Denda mendekati target. Tingkatkan closing akun dengan potensi denda/penalty tertinggi.";
        } else {
            $bullets[] = "Pendapatan Denda masih rendah. Identifikasi akun dengan potensi penalty & dorong skema penyelesaian yang menghasilkan pendapatan.";
        }

        // ========= Insight Naik Level: kualitas recovery =========
        // Kalau penalty masuk tapi OS turun kecil → indikasi bayar denda tanpa pokok
        $penalty = (float)($row->penalty_paid_total ?? 0);
        $osTurun = (float)($row->os_kol2_turun_total ?? 0);

        // guard: kalau osTurun kecil tapi penalty ada
        if ($penalty > 0 && $osTurun > 0) {
            $ratio = $penalty / max(1, $osTurun); // safety
            // ratio kecil normal, ratio besar patut dicermati (bisa bayar penalty tapi pokok minim)
            if ($ratio > 0.02) { // 2% dari OS turun (silakan tuning)
                $bullets[] = "Catatan kualitas recovery: porsi penalty relatif tinggi dibanding penurunan OS. Pastikan pembayaran tidak hanya penalty, tetapi juga menurunkan pokok.";
            }
        } elseif ($penalty > 0 && $osTurun <= 0) {
            $bullets[] = "Catatan kualitas recovery: ada penalty masuk namun OS belum turun. Pastikan ada pembayaran pokok/penurunan saldo agar OS benar-benar membaik.";
        }

        return [
            'bullets' => $bullets,
            'badges'  => [
                'os'      => $osBadge,
                'migrasi' => $migBadge,
                'penalty' => $penBadge,
            ],
            'insights' => [
                'penalty_to_os_ratio' => ($osTurun > 0) ? ($penalty / $osTurun) : null,
            ],
        ];
    }
}
