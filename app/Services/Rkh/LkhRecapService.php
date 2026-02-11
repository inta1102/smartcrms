<?php

namespace App\Services\Rkh;

use App\Models\RkhHeader;

class LkhRecapService
{
    /**
     * Rekap LKH untuk 1 hari (1 RKH Header)
     * Return array siap view / export
     */
    public function build(RkhHeader $rkh): array
    {
        $rkh->loadMissing([
            'user',
            'approver',
            'details' => function ($q) {
                $q->orderBy('jam_mulai');
            },
            'details.lkh',
            'details.networking',
        ]);

        $rows = [];
        foreach ($rkh->details as $d) {
            $rows[] = [
                'jam' => $this->fmtJam($d->jam_mulai, $d->jam_selesai),
                'nasabah' => $d->nama_nasabah ?: '-',
                'kolek' => $d->kolektibilitas ?: '-',
                'jenis' => $d->jenis_kegiatan,
                'tujuan' => $d->tujuan_kegiatan,
                'area' => $d->area ?: '-',

                'is_visited' => $d->lkh?->is_visited,
                'hasil' => $d->lkh?->hasil_kunjungan,
                'respon' => $d->lkh?->respon_nasabah,
                'tindak_lanjut' => $d->lkh?->tindak_lanjut,

                'networking' => $d->networking ? [
                    'nama_relasi' => $d->networking->nama_relasi,
                    'jenis_relasi' => $d->networking->jenis_relasi,
                    'potensi' => $d->networking->potensi,
                    'follow_up' => $d->networking->follow_up,
                ] : null,
            ];
        }

        // Statistik ringan: berapa kegiatan sudah ada LKH
        $total = count($rows);
        $filled = 0;
        foreach ($rkh->details as $d) {
            if ($d->lkh) $filled++;
        }

        return [
            'rkh' => $rkh,
            'rows' => $rows,
            'stats' => [
                'total_kegiatan' => $total,
                'lkh_terisi' => $filled,
                'lkh_kosong' => max(0, $total - $filled),
            ],
        ];
    }

    private function fmtJam(string $start, string $end): string
    {
        // DB time biasanya "HH:MM:SS", kita ringkas
        return substr($start, 0, 5) . ' - ' . substr($end, 0, 5);
    }
}
