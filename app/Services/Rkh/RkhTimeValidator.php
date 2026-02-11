<?php

namespace App\Services\Rkh;

use Carbon\Carbon;

class RkhTimeValidator
{
    /**
     * Validasi list kegiatan per hari.
     *
     * @param array $items contoh:
     * [
     *   ['jam_mulai' => '08:00', 'jam_selesai' => '10:00', ...],
     *   ...
     * ]
     *
     * @param array $opt:
     *  - gap_tolerance_minutes (int) default 30 -> gap kecil masih oke (perjalanan/istirahat)
     *  - max_gap_minutes (int|null) default 120 -> kalau gap lebih dari ini dianggap bolong parah
     *  - require_no_overlap (bool) default true
     *  - min_total_minutes (int|null) default 360 (6 jam) -> opsional
     *  - work_start (string|null) default null -> contoh "08:00"
     *  - work_end (string|null) default null -> contoh "16:30"
     *
     * @return array ['ok'=>bool, 'errors'=>array, 'meta'=>array]
     */
    public function validate(array $items, array $opt = []): array
    {
        $gapTolerance = (int)($opt['gap_tolerance_minutes'] ?? 30);
        $maxGap       = $opt['max_gap_minutes'] ?? 120; // null = tidak cek max gap
        $requireNoOv  = (bool)($opt['require_no_overlap'] ?? true);

        $minTotal     = $opt['min_total_minutes'] ?? null;

        $workStart    = $opt['work_start'] ?? null;
        $workEnd      = $opt['work_end'] ?? null;

        $errors = [];
        $meta = [
            'total_minutes' => 0,
            'gaps' => [],
            'normalized' => [],
        ];

        // 1) normalize + basic checks
        $normalized = [];
        foreach ($items as $i => $row) {
            $startRaw = trim((string)($row['jam_mulai'] ?? ''));
            $endRaw   = trim((string)($row['jam_selesai'] ?? ''));

            if ($startRaw === '' || $endRaw === '') {
                $errors[] = "Baris #".($i+1).": jam_mulai/jam_selesai wajib diisi.";
                continue;
            }

            $start = $this->toMinute($startRaw);
            $end   = $this->toMinute($endRaw);

            if ($start === null || $end === null) {
                $errors[] = "Baris #".($i+1).": format jam tidak valid (pakai HH:MM).";
                continue;
            }

            if ($end <= $start) {
                $errors[] = "Baris #".($i+1).": jam_selesai harus lebih besar dari jam_mulai.";
                continue;
            }

            // optional: cek jam kerja
            if ($workStart !== null) {
                $ws = $this->toMinute($workStart);
                if ($ws !== null && $start < $ws) {
                    $errors[] = "Baris #".($i+1).": jam_mulai lebih awal dari jam kerja ({$workStart}).";
                }
            }
            if ($workEnd !== null) {
                $we = $this->toMinute($workEnd);
                if ($we !== null && $end > $we) {
                    $errors[] = "Baris #".($i+1).": jam_selesai melewati jam kerja ({$workEnd}).";
                }
            }

            $normalized[] = [
                'idx' => $i,
                'start' => $start,
                'end' => $end,
                'start_raw' => $startRaw,
                'end_raw' => $endRaw,
            ];
        }

        // kalau basic errors sudah banyak, tetap lanjut overlap biar informatif
        usort($normalized, fn($a,$b) => $a['start'] <=> $b['start']);
        $meta['normalized'] = $normalized;

        // 2) overlap check + total minutes + gap check
        $total = 0;
        $prev = null;

        foreach ($normalized as $k => $cur) {
            $dur = $cur['end'] - $cur['start'];
            $total += $dur;

            if ($prev) {
                // overlap
                if ($requireNoOv && $cur['start'] < $prev['end']) {
                    $errors[] =
                        "Overlap: Baris #".($cur['idx']+1)." ({$cur['start_raw']}-{$cur['end_raw']}) ".
                        "tumpang tindih dengan Baris #".($prev['idx']+1)." ({$prev['start_raw']}-{$prev['end_raw']}).";
                }

                // gap
                $gap = $cur['start'] - $prev['end'];
                if ($gap > 0) {
                    $meta['gaps'][] = [
                        'from' => $prev['end'],
                        'to' => $cur['start'],
                        'minutes' => $gap,
                        'note' => $this->fmtGapNote($gap, $gapTolerance, $maxGap),
                    ];

                    // gap besar dianggap bolong (opsional)
                    if ($maxGap !== null && $gap > (int)$maxGap) {
                        $errors[] =
                            "Jam kosong terlalu besar ({$gap} menit) antara Baris #".($prev['idx']+1).
                            " dan Baris #".($cur['idx']+1).".";
                    }
                }
            }

            // update prev: tapi perlu handle kalau overlap -> prev end ambil yang paling besar (biar gap hitung benar)
            if (!$prev) {
                $prev = $cur;
            } else {
                if ($cur['end'] > $prev['end']) $prev['end'] = $cur['end'];
                // keep prev idx for message? gap message sudah keburu dihitung sebelum merge end.
            }
        }

        $meta['total_minutes'] = $total;

        if ($minTotal !== null && $total < (int)$minTotal) {
            $errors[] = "Total jam kerja terlalu kecil: {$total} menit (minimal {$minTotal} menit).";
        }

        return [
            'ok' => count($errors) === 0,
            'errors' => $errors,
            'meta' => $meta,
        ];
    }

    private function toMinute(string $hm): ?int
    {
        // accept HH:MM or HH:MM:SS
        $hm = trim($hm);
        if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $hm)) return null;

        try {
            $t = Carbon::createFromFormat(str_contains($hm, ':') && substr_count($hm, ':') === 2 ? 'H:i:s' : 'H:i', $hm);
        } catch (\Throwable $e) {
            return null;
        }

        return ((int)$t->format('H')) * 60 + (int)$t->format('i');
    }

    private function fmtGapNote(int $gap, int $tolerance, $maxGap): string
    {
        if ($gap <= $tolerance) return "gap wajar (≤ {$tolerance} menit)";
        if ($maxGap !== null && $gap > (int)$maxGap) return "gap besar (>{${'maxGap'}} menit)";
        return "gap sedang";
    }
}
