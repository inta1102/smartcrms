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
            'details.latestRoVisit',
            'details.networking',
        ]);

        $rows = [];
        $filled = 0;

        foreach ($rkh->details as $d) {
            $visit = $d->latestRoVisit;
            $lkh   = $d->lkh;

            $visitStatus = strtoupper(trim((string)($visit->status ?? '')));
            $visitDone   = ($visitStatus === 'DONE');

            // source utama: ro_visits.lkh_note
            // fallback: lkh lama
            $hasil = trim((string)(
                $visit->lkh_note
                ?? $lkh->hasil_kunjungan
                ?? ''
            ));

            $respon = trim((string)(
                $lkh->respon_nasabah
                ?? ''
            ));

            $tindakLanjut = trim((string)(
                $visit->next_action
                ?? $lkh->tindak_lanjut
                ?? ''
            ));

            // visited state
            if ($visit) {
                $isVisited = $visitDone;
            } elseif ($lkh) {
                $isVisited = $lkh->is_visited;
            } else {
                $isVisited = null;
            }

            // hitung terisi:
            // - visit done
            // - atau ada hasil
            // - atau ada row lkh lama
            $isFilled = $visitDone || $hasil !== '' || !empty($lkh);
            if ($isFilled) {
                $filled++;
            }

            $rows[] = [
                'jam' => $this->fmtJam((string)$d->jam_mulai, (string)$d->jam_selesai),
                'nasabah' => $d->nama_nasabah ?: '-',
                'kolek' => $d->kolektibilitas ?: '-',
                'jenis' => $d->jenis_kegiatan,
                'tujuan' => $d->tujuan_kegiatan,
                'area' => $d->area ?: '-',

                'is_visited' => $isVisited,
                'hasil' => $hasil,
                'respon' => $respon,
                'tindak_lanjut' => $tindakLanjut,

                // penanda sumber untuk blade
                'source_visit_done' => $visitDone,

                'networking' => $d->networking ? [
                    'nama_relasi' => $d->networking->nama_relasi,
                    'jenis_relasi' => $d->networking->jenis_relasi,
                    'potensi' => $d->networking->potensi,
                    'follow_up' => $d->networking->follow_up,
                ] : null,
            ];
        }

        $total = count($rows);

        return [
            'rkh' => $rkh,
            'role' => strtoupper((string)($rkh->user?->level?->value ?? $rkh->user?->level ?? 'RO')),
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
        return substr($start, 0, 5) . ' - ' . substr($end, 0, 5);
    }
}