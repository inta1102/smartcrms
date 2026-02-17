<?php

namespace App\Services\Kpi;

class BeKpiInterpretationService
{
    private function pct(float $actual, float $target): float
    {
        if ($target <= 0) return $actual > 0 ? 200.0 : 0.0;
        return ($actual / $target) * 100.0;
    }

    private function fmtRp($n): string
    {
        return 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
    }

    private function fmtNum($n): string
    {
        return number_format((float)($n ?? 0), 0, ',', '.');
    }

    private function fmtPct($n): string
    {
        return number_format((float)($n ?? 0), 1, ',', '.') . '%';
    }

    private function badgeFromPct(float $pct, bool $higherIsBetter = true): array
    {
        // standar sederhana: >=100 on track, 75-99 warning, <75 critical
        // untuk metric "lebih kecil lebih baik" bisa kamu pakai $higherIsBetter=false (opsional)
        if (!$higherIsBetter) {
            // kalau lebih kecil lebih baik, invert logic (jarang dipakai untuk BE, tapi disiapkan)
            $pct = 200 - $pct;
        }

        if ($pct >= 100) return ['label' => 'On Track', 'class' => 'bg-emerald-100 text-emerald-800 border-emerald-200'];
        if ($pct >= 75)  return ['label' => 'Warning',  'class' => 'bg-amber-100 text-amber-800 border-amber-200'];
        return ['label' => 'Critical', 'class' => 'bg-rose-100 text-rose-800 border-rose-200'];
    }

    public function build(object $row): array
    {
        // ambil target fix dari controller (field *_fix)
        $tOs    = (float)($row->target_os_selesai_fix ?? 0);
        $tNoa   = (float)($row->target_noa_selesai_fix ?? 0);
        $tBunga = (float)($row->target_bunga_masuk_fix ?? 0);
        $tDenda = (float)($row->target_denda_masuk_fix ?? 0);

        $aOs    = (float)($row->actual_os_selesai ?? 0);
        $aNoa   = (float)($row->actual_noa_selesai ?? 0);
        $aBunga = (float)($row->actual_bunga_masuk ?? 0);
        $aDenda = (float)($row->actual_denda_masuk ?? 0);

        $pOs    = $this->pct($aOs, $tOs);
        $pNoa   = $this->pct($aNoa, $tNoa);
        $pBunga = $this->pct($aBunga, $tBunga);
        $pDenda = $this->pct($aDenda, $tDenda);

        // gap (berapa kurang menuju target)
        $gapOs    = max(0, $tOs - $aOs);
        $gapNoa   = max(0, $tNoa - $aNoa);
        $gapBunga = max(0, $tBunga - $aBunga);
        $gapDenda = max(0, $tDenda - $aDenda);

        $status = strtoupper((string)($row->status ?? 'DRAFT'));

        // NPL insight
        $nplPrev = (float)($row->os_npl_prev ?? 0);
        $nplNow  = (float)($row->os_npl_now ?? 0);
        $nplDrop = (float)($row->net_npl_drop ?? ($nplPrev - $nplNow));

        $nplDirection = $nplDrop >= 0 ? 'membaik' : 'memburuk';
        $nplAbs = abs($nplDrop);

        // tentukan prioritas: ambil 2 gap terbesar (berdasarkan nominal; NOA pakai gap count)
        $gaps = [
            ['k' => 'OS selesai',   'type' => 'money', 'gap' => $gapOs,    'pct' => $pOs],
            ['k' => 'Bunga masuk',  'type' => 'money', 'gap' => $gapBunga, 'pct' => $pBunga],
            ['k' => 'Denda masuk',  'type' => 'money', 'gap' => $gapDenda, 'pct' => $pDenda],
            ['k' => 'NOA selesai',  'type' => 'count', 'gap' => $gapNoa,   'pct' => $pNoa],
        ];

        usort($gaps, function($a, $b) {
            // money gap diprioritaskan, lalu count
            $wa = ($a['type'] === 'money') ? 2 : 1;
            $wb = ($b['type'] === 'money') ? 2 : 1;
            if ($wa !== $wb) return $wb <=> $wa;
            return ($b['gap'] <=> $a['gap']);
        });

        $top1 = $gaps[0] ?? null;
        $top2 = $gaps[1] ?? null;

        // bullets "cerdas"
        $bullets = [];

        // 1) status data
        if (in_array($status, ['RECALC','DRAFT'], true)) {
            $bullets[] = "Status data masih <b>{$status}</b> → angka bisa berubah setelah perhitungan final/approval (pastikan builder & mapping transaksi sudah konsisten).";
        } elseif ($status === 'SUBMITTED') {
            $bullets[] = "Status <b>SUBMITTED</b> → pastikan bukti penyelesaian & penerimaan bunga/denda sudah lengkap sebelum approval.";
        } else {
            $bullets[] = "Status <b>{$status}</b> → gunakan sebagai rujukan eksekusi & evaluasi bulanan.";
        }

        // 2) ringkasan performa berbasis achievement, bukan mengulang angka
        $bullets[] = "Pencapaian bulan ini: OS selesai <b>{$this->fmtPct($pOs)}</b>, NOA selesai <b>{$this->fmtPct($pNoa)}</b>, bunga <b>{$this->fmtPct($pBunga)}</b>, denda <b>{$this->fmtPct($pDenda)}</b>.";

        // 3) fokus gap terbesar
        if ($top1) {
            $gapText = $top1['type'] === 'money'
                ? $this->fmtRp($top1['gap'])
                : $this->fmtNum($top1['gap']) . " case";
            $bullets[] = "Gap terbesar ada di <b>{$top1['k']}</b> (kurang {$gapText} untuk mencapai target) → ini prioritas #1.";
        }
        if ($top2) {
            $gapText = $top2['type'] === 'money'
                ? $this->fmtRp($top2['gap'])
                : $this->fmtNum($top2['gap']) . " case";
            $bullets[] = "Prioritas #2: <b>{$top2['k']}</b> (kurang {$gapText}).";
        }

        // 4) insight khusus denda/bunga jika sangat rendah vs target
        if ($tDenda > 0 && $pDenda < 10) {
            $bullets[] = "Denda sangat rendah dibanding target → cek: (1) denda belum ditagih/ditetapkan di dokumen settlement, atau (2) mapping transaksi denda belum masuk ke sumber data KPI.";
        }
        if ($tBunga > 0 && $pBunga < 60) {
            $bullets[] = "Bunga masih di bawah target → dorong strategi recovery yang menghasilkan arus kas (bunga) selain hanya penyelesaian administratif.";
        }

        // 5) NPL narrative (nilai tambah)
        $bullets[] = "Kualitas portofolio NPL {$nplDirection}: net drop <b>{$this->fmtRp($nplAbs)}</b> (Prev {$this->fmtRp($nplPrev)} → Now {$this->fmtRp($nplNow)}).";

        // badges per komponen
        $badges = [
            'status' => $status,
            'os_selesai'   => $this->badgeFromPct($pOs),
            'noa_selesai'  => $this->badgeFromPct($pNoa),
            'bunga_masuk'  => $this->badgeFromPct($pBunga),
            'denda_masuk'  => $this->badgeFromPct($pDenda),
        ];

        // insights tambahan (bisa dipakai card opsional)
        $insights = [
            'ach_os_pct' => $pOs,
            'ach_noa_pct' => $pNoa,
            'ach_bunga_pct' => $pBunga,
            'ach_denda_pct' => $pDenda,
            'gap_os' => $gapOs,
            'gap_noa' => $gapNoa,
            'gap_bunga' => $gapBunga,
            'gap_denda' => $gapDenda,
        ];

        return compact('bullets','badges','insights');
    }
}
