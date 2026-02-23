<?php

namespace App\Services\Kpi;

use Illuminate\Support\Collection;

class KsbeLeadershipAiEngine
{
    public function build(array $ksbe): array
    {
        $li   = (float) data_get($ksbe, 'li.total', 0); // kalau sudah ada
        $pi   = (float) data_get($ksbe, 'li.pi_scope', data_get($ksbe, 'pi_scope', 0));
        $si   = (float) data_get($ksbe, 'li.stability', data_get($ksbe, 'stability', 0));
        $risk = (float) data_get($ksbe, 'li.risk', data_get($ksbe, 'risk', 0));
        $imp  = (float) data_get($ksbe, 'li.improve', data_get($ksbe, 'improve', 0));

        /** @var Collection $items */
        $items = data_get($ksbe, 'items');
        $items = $items instanceof Collection ? $items : collect($items ?: []);

        // ===== 1) Status / Grade tim =====
        $status = $this->statusFromLi($li, $pi, $si, $risk, $imp);

        // ===== 2) Signals =====
        // Improve sering "0" karena MoM belum ada baseline (bukan performa jelek).
        // Kita tandai NA supaya narasi tidak salah.
        $impNa = $this->isImproveNa($ksbe, $imp);

        $signals = [
            'pi_scope'  => $this->signal('PI_scope', $pi, 3.0, 4.0, 'Target/Actual agregat'),
            'stability' => $this->signal('Stability', $si, 3.0, 4.0, 'Coverage • Spread • Bottom'),
            'risk'      => $this->signal('Risk', $risk, 3.0, 4.0, 'NPL drop & risk control'),
            'improve'   => $this->signal('Improve', $imp, 2.5, 3.5, 'MoM trend', $impNa, $impNa ? 'MoM belum ada (baseline belum tersedia)' : null),
        ];

        // ===== 3) Derived metrics (gap & distribution) =====
        $top3    = $items->sortByDesc(fn ($x) => (float) data_get($x, 'pi.total', 0))->take(3)->values();
        $bottom3 = $items->sortBy(fn ($x) => (float) data_get($x, 'pi.total', 0))->take(3)->values();

        // Gap KPI dari recap target vs actual (agregat)
        $tOs  = (float) data_get($ksbe, 'recap.target.os', 0);
        $aOs  = (float) data_get($ksbe, 'recap.actual.os', 0);
        $gapOs = max(0, $tOs - $aOs);

        $tNoa  = (float) data_get($ksbe, 'recap.target.noa', 0);
        $aNoa  = (float) data_get($ksbe, 'recap.actual.noa', 0);
        $gapNoa = max(0, $tNoa - $aNoa);

        $tB  = (float) data_get($ksbe, 'recap.target.bunga', 0);
        $aB  = (float) data_get($ksbe, 'recap.actual.bunga', 0);
        $gapB = max(0, $tB - $aB);

        $tD  = (float) data_get($ksbe, 'recap.target.denda', 0);
        $aD  = (float) data_get($ksbe, 'recap.actual.denda', 0);
        $gapD = max(0, $tD - $aD);

        // ===== 4) Priorities (3 fokus) =====
        $priorities = $this->pickPriorities([
            'os'    => $gapOs,
            'noa'   => $gapNoa,
            'bunga' => $gapB,
            'denda' => $gapD,
        ], $signals, $bottom3);

        // ===== 5) Actions (playbook) =====
        $actions = $this->buildActions($priorities, $top3, $bottom3);

        // ===== 6) Summary narrative (AUTO: beda status -> beda narasi) =====
        $summary = $this->buildSummary($status, $signals, $priorities, [
            'gaps' => ['os' => $gapOs, 'noa' => $gapNoa, 'bunga' => $gapB, 'denda' => $gapD],
            'top3' => $top3,
            'bottom3' => $bottom3,
            'items_cnt' => (int) $items->count(),
        ]);

        return [
            'status'     => $status,
            'signals'    => $signals,
            'priorities' => $priorities,
            'actions'    => $actions,
            'coaching'   => [
                'top3' => $top3->map(fn ($x) => [
                    'name' => data_get($x, 'name', '-'),
                    'code' => data_get($x, 'code', ''),
                    'pi'   => (float) data_get($x, 'pi.total', 0),
                ])->all(),
                'bottom3' => $bottom3->map(fn ($x) => [
                    'name' => data_get($x, 'name', '-'),
                    'code' => data_get($x, 'code', ''),
                    'pi'   => (float) data_get($x, 'pi.total', 0),
                ])->all(),
            ],
            'summary'    => $summary,
        ];
    }

    private function statusFromLi(float $li, float $pi, float $si, float $risk, float $imp): array
    {
        // fallback kalau LI belum dihitung: pakai rata-rata komponen
        if ($li <= 0) {
            $li = ($pi * 0.55) + ($si * 0.20) + ($risk * 0.15) + ($imp * 0.10);
        }

        if ($li >= 4.0) return ['label' => 'SEHAT',    'tone' => 'success', 'score' => round($li, 2)];
        if ($li >= 3.0) return ['label' => 'ON TRACK', 'tone' => 'info',    'score' => round($li, 2)];
        if ($li >= 2.0) return ['label' => 'WASPADA',  'tone' => 'warning', 'score' => round($li, 2)];
        return              ['label' => 'KRITIS',  'tone' => 'danger',  'score' => round($li, 2)];
    }

    private function signal(
        string $name,
        float $val,
        float $warnAt,
        float $goodAt,
        string $desc,
        bool $na = false,
        ?string $naWhy = null
    ): array {
        if ($na) {
            return [
                'name'  => $name,
                'value' => round($val, 2),
                'level' => 'na',
                'na'    => true,
                'why'   => $naWhy ?: $desc,
            ];
        }

        $level = 'low';
        if ($val >= $goodAt) $level = 'good';
        elseif ($val >= $warnAt) $level = 'warn';

        return [
            'name'  => $name,
            'value' => round($val, 2),
            'level' => $level,
            'na'    => false,
            'why'   => $desc,
        ];
    }

    private function isImproveNa(array $ksbe, float $imp): bool
    {
        // Kalau ada flag meta dari service LI, pakai itu (paling akurat)
        $flag = data_get($ksbe, 'li.improve_na', null);
        if ($flag !== null) return (bool) $flag;

        // Heuristik aman: Improve = 0 dan tidak ada data pembanding prev period -> anggap NA
        // (kalau kamu sudah punya key li.prev_period / li.has_prev, silakan sesuaikan)
        $hasPrev = data_get($ksbe, 'li.has_prev', null);
        if ($hasPrev !== null) {
            return ($imp <= 0) && ((bool) $hasPrev === false);
        }

        // default: kalau imp 0 kita anggap NA (supaya narasi nggak menyesatkan)
        // Kalau nanti kamu sudah punya MoM valid, set li.improve_na=false.
        return $imp <= 0;
    }

    private function pickPriorities(array $gaps, array $signals, Collection $bottom3): array
    {
        // urut gap terbesar
        arsort($gaps);

        $picked = [];
        foreach ($gaps as $k => $gap) {
            if ($gap <= 0) continue;

            $picked[] = match ($k) {
                'os' => [
                    'key'    => 'os_recovery',
                    'title'  => 'Recovery OS',
                    'reason' => 'Gap OS terbesar vs target',
                    'impact' => 'Turunkan NPL & naikkan cash recovery',
                ],
                'bunga' => [
                    'key'    => 'bunga',
                    'title'  => 'Bunga Masuk',
                    'reason' => 'Bunga jauh dari target',
                    'impact' => 'Perbaiki penerimaan bunga recovery',
                ],
                'denda' => [
                    'key'    => 'denda',
                    'title'  => 'Denda Masuk',
                    'reason' => 'Denda jauh dari target',
                    'impact' => 'Dorong penyelesaian yang menghasilkan denda',
                ],
                'noa' => [
                    'key'    => 'noa',
                    'title'  => 'NOA Selesai',
                    'reason' => 'Throughput penyelesaian rendah',
                    'impact' => 'Naikkan jumlah kasus selesai',
                ],
                default => null
            };

            if (count($picked) >= 2) break;
        }

        // slot ke-3: stability/risk coaching
        $needStability = data_get($signals, 'stability.level') !== 'good';
        $needRisk      = data_get($signals, 'risk.level') !== 'good';

        if ($needStability) {
            $picked[] = [
                'key'    => 'discipline',
                'title'  => 'Stabilitas & Disiplin Eksekusi',
                'reason' => 'Stability belum sehat (spread/bottom)',
                'impact' => 'Kurangi gap antar BE (bottom naik)',
            ];
        } elseif ($needRisk) {
            $picked[] = [
                'key'    => 'risk_control',
                'title'  => 'Risk Control',
                'reason' => 'Risk index belum kuat',
                'impact' => 'Percepat NPL drop & kurangi rollback kolek',
            ];
        } else {
            $picked[] = [
                'key'    => 'consistency',
                'title'  => 'Consistency',
                'reason' => 'Gap utama sudah tertutup, fokus sustain',
                'impact' => 'Jaga performa stabil di semua BE',
            ];
        }

        return array_slice($picked, 0, 3);
    }

    private function buildActions(array $priorities, Collection $top3, Collection $bottom3): array
    {
        $actions = [];

        foreach ($priorities as $p) {
            switch ($p['key']) {
                case 'os_recovery':
                    $actions[] = [
                        'title' => 'War Room Recovery OS (7 hari)',
                        'steps' => [
                            'Ambil TOP 20 account prev kolek 3/4/5 terbesar di scope.',
                            'Lock target mingguan: OS recovery minimal X.',
                            'Daily standup 15 menit: status action (janji bayar, restruktur, eksekusi agunan, litigasi).',
                            'Validasi closure: hanya LUNAS dihitung sebagai selesai.',
                        ],
                        'metric' => 'OS recovery weekly ↑',
                        'who'    => 'KSBE + seluruh BE',
                    ];
                    break;

                case 'noa':
                    $actions[] = [
                        'title' => 'Pipeline NOA Selesai (throughput)',
                        'steps' => [
                            'Pisah funnel: (1) Cure ke kolek 1/2, (2) Closure LUNAS.',
                            'Set SLA: tiap BE wajib close/cure minimal N kasus/minggu.',
                            'Audit mismatch: NOA selesai harus selaras rule OS recovery.',
                        ],
                        'metric' => 'NOA selesai ↑ dan selaras OS',
                        'who'    => 'KSBE + BE (coaching)',
                    ];
                    break;

                case 'bunga':
                    $actions[] = [
                        'title' => 'Drive Bunga Masuk dari Recovery',
                        'steps' => [
                            'Tag accounts yang punya bunga tertunggak besar.',
                            'Buat skenario bayar: cicil tunggakan vs pelunasan.',
                            'Monitor realisasi bunga harian.',
                        ],
                        'metric' => 'Bunga masuk ↑',
                        'who'    => 'BE + TL terkait',
                    ];
                    break;

                case 'denda':
                    $actions[] = [
                        'title' => 'Monetisasi Denda (aturan & eksekusi)',
                        'steps' => [
                            'Pastikan rule denda konsisten per produk.',
                            'Cari accounts yang eligible denda saat closure.',
                            'Validasi input denda saat posting.',
                        ],
                        'metric' => 'Denda masuk ↑',
                        'who'    => 'BE + Ops',
                    ];
                    break;

                case 'discipline':
                    $actions[] = [
                        'title' => 'Coaching Bottom Performers (2 minggu)',
                        'steps' => [
                            'Ambil Bottom 2 BE berdasarkan PI.',
                            'Review 10 kasus terbesar masing-masing: hambatan & next action.',
                            'Buat “Daily Commitment”: minimal 1 progress nyata/hari.',
                            'Shadowing best practice dari Top 1 BE.',
                        ],
                        'metric' => 'PI bottom naik + spread turun',
                        'who'    => 'KSBE',
                    ];
                    break;

                case 'risk_control':
                    $actions[] = [
                        'title' => 'Stop-Bleeding Risk (10 akun risiko terbesar)',
                        'steps' => [
                            'Kunci list 10 akun risiko terbesar (OS tertinggi, kolek rawan naik).',
                            'Set next action wajib + due date (kunjungan/telepon/somasi/restruktur).',
                            'Review 2x seminggu: progress, hambatan, eskalasi.',
                        ],
                        'metric' => 'Rollback kolek ↓, NPL drop ↑',
                        'who'    => 'KSBE + BE',
                    ];
                    break;
            }
        }

        // tambah 1 action generik: mentoring top -> bottom
        if ($top3->isNotEmpty() && $bottom3->isNotEmpty()) {
            $actions[] = [
                'title' => 'Mentoring: Top BE → Bottom BE',
                'steps' => [
                    'Top 1 BE sharing playbook: cara memilih case & menutup cepat.',
                    'Pairing 1:1: Top 1 dampingi Bottom 1 selama 1 minggu.',
                ],
                'metric' => 'Spread PI mengecil',
                'who'    => 'KSBE',
            ];
        }

        return $actions;
    }

    private function buildSummary(array $status, array $signals, array $priorities, array $ctx = []): array
    {
        $label = (string) ($status['label'] ?? 'WASPADA');

        $issues = $this->pickIssues($signals); // 2 weakest (tanpa NA)
        $issueTitles = array_map(function ($k) use ($signals) {
            return (string) data_get($signals, "$k.name", strtoupper($k));
        }, $issues);

        $focus = array_map(fn ($p) => "Fokus: {$p['title']} — {$p['reason']}", $priorities);

        // headline by status (beda status -> beda narasi)
        $headline = match ($label) {
            'SEHAT'    => 'Performa tim BE stabil dan kuat. Fokus pada scaling dan menjaga konsistensi antar anggota.',
            'ON TRACK' => 'Tim BE berjalan on-track. Ada beberapa titik yang perlu diperkuat agar tidak “bocor” di akhir bulan.',
            'WASPADA'  => 'Tim BE butuh intervensi terarah. Ada gap dan/atau stabilitas yang belum aman untuk dibiarkan.',
            default   => 'Tim BE berada di zona kritis. Perlu leadership system yang tegas (coaching, kontrol, eksekusi) agar performa bisa naik dan stabil.',
        };

        // meaning text (yang kamu minta: bukan narasi sama untuk semua)
        $meaning = match ($label) {
            'SEHAT' => 'Indikator ini menunjukkan **leadership system sudah berjalan**: target tercapai secara agregat, variasi antar BE terjaga, dan kontrol risiko relatif stabil.',
            'ON TRACK' => 'Indikator ini menunjukkan **arah tim sudah benar**, namun masih ada “lubang” di beberapa metrik/anggota yang bisa menahan capaian akhir bulan jika tidak dikunci lebih awal.',
            'WASPADA' => 'Indikator ini menunjukkan **tim belum cukup stabil**. Bukan sekadar “hasil sementara”, tapi sinyal bahwa ritme kontrol & coaching perlu diperketat supaya bottom performer naik dan gap mengecil.',
            default => 'Indikator ini bukan cuma “tim lagi jelek”. Ini sinyal bahwa **leadership system (coaching, kontrol, eksekusi)** belum terbentuk kuat/terukur sehingga performa tidak stabil dan sulit diprediksi.',
        };

        // why (berdasarkan issues)
        $why = [];
        foreach ($issues as $k) {
            if ($k === 'pi_scope') {
                $why[] = 'PI_scope rendah → pencapaian agregat target/actual masih jauh dari aman pada KPI tertentu.';
            } elseif ($k === 'stability') {
                $why[] = 'Stability belum kuat → gap Top vs Bottom masih lebar (spread besar), bottom performer menahan hasil tim.';
            } elseif ($k === 'risk') {
                $why[] = 'Risk belum kuat → kontrol risiko belum konsisten, penurunan NPL/risiko belum “terkunci”.';
            } elseif ($k === 'improve') {
                $why[] = 'Improve lemah/NA → tren MoM belum bisa dipakai sebagai kendali (baseline belum tersedia).';
            }
        }
        if (empty($why) && $label === 'SEHAT') {
            $why[] = 'Komponen-komponen utama (PI_scope, Stability, Risk) berada pada level yang mendukung performa stabil.';
        }

        // actions now (langsung bisa dieksekusi, beda status beda “tone”)
        $actionsNow = [];
        if (in_array('stability', $issues, true)) {
            $actionsNow[] = 'Kunci program pairing: Top 1 dampingi Bottom 1 selama 1 minggu + review 10 case terbesar.';
        }
        if (in_array('pi_scope', $issues, true)) {
            $actionsNow[] = 'Tetapkan 1 KPI fokus minggu ini berdasarkan gap terbesar (Target - Actual) dari recap.';
        }
        if (in_array('risk', $issues, true)) {
            $actionsNow[] = 'Buat stop-bleeding list 10 akun risiko terbesar + next action wajib dan due date.';
        }
        if ((bool) data_get($signals, 'improve.na', false) === true) {
            $actionsNow[] = 'Aktifkan baseline prev month agar MoM/Improve bisa dihitung dan dipakai sebagai kendali.';
        }

        // kalau kosong, isi default per status
        if (empty($actionsNow)) {
            $actionsNow = match ($label) {
                'SEHAT' => [
                    'Pertahankan ritme kontrol mingguan dan sharing playbook antar BE.',
                    'Jaga bottom performer tidak turun (monitor spread PI).',
                ],
                'ON TRACK' => [
                    'Kunci 1 KPI fokus (gap terbesar) + review progress 2x seminggu.',
                    'Naikkan bottom performer agar spread mengecil.',
                ],
                'WASPADA' => [
                    'War room 7 hari untuk KPI gap terbesar.',
                    'Pairing top-bottom + daily commitment 1 progress/hari.',
                ],
                default => [
                    'Aktifkan war room (OS/NOA) + stop-bleeding risk list.',
                    'Pairing top-bottom + kontrol harian selama 2 minggu.',
                ],
            };
        }

        // bullets signal (tetap tampil ringkas)
        $bullets = [];
        foreach (['pi_scope', 'stability', 'risk', 'improve'] as $k) {
            $lvl = (string) data_get($signals, "$k.level");
            $v   = (float) data_get($signals, "$k.value");
            $nm  = (string) data_get($signals, "$k.name");
            if ($lvl === 'na') {
                $bullets[] = "{$nm} = {$v} (NA) — " . (string) data_get($signals, "$k.why");
            } else {
                $bullets[] = "{$nm} = {$v} (" . strtoupper($lvl) . ")";
            }
        }

        // small footer: highlight 2 weakest
        $weakNote = null;
        if (!empty($issueTitles)) {
            $weakNote = 'Indikator terlemah saat ini: ' . implode(' + ', $issueTitles) . '.';
        }

        return [
            'headline'    => $headline,
            'meaning'     => $meaning,
            'why'         => $why,
            'actions_now' => $actionsNow,
            'bullets'     => $bullets,
            'focus'       => $focus,
            'weak_note'   => $weakNote,
        ];
    }

    private function pickIssues(array $signals, int $take = 2): array
    {
        // ambil yang paling lemah berdasarkan level/value, skip NA
        // level priority: low < warn < good
        $rank = ['low' => 1, 'warn' => 2, 'good' => 3];

        return collect($signals)
            ->map(function ($s, $k) use ($rank) {
                $lvl = (string) ($s['level'] ?? 'low');
                $na  = (bool) ($s['na'] ?? false);
                return [
                    'k' => (string) $k,
                    'na' => $na,
                    'r' => $rank[$lvl] ?? 0,
                    'v' => (float) ($s['value'] ?? 0),
                ];
            })
            ->filter(fn ($x) => $x['na'] === false)
            ->sortBy(function ($x) {
                // yang lebih lemah dulu: rank kecil, lalu value kecil
                return ($x['r'] * 1000) + $x['v'];
            })
            ->take($take)
            ->pluck('k')
            ->values()
            ->all();
    }

    private function calcKsbeStabilityIndex(Collection $items): array
    {
        $pis = $items->map(fn($x) => (float) data_get($x, 'pi.total', 0))->values();
        $n = $pis->count();

        if ($n <= 0) {
            return [
                'score' => 0.0,
                'coverage' => 0.0,
                'spread' => 0.0,
                'bottom_avg' => 0.0,
                'parts' => ['coverage'=>0,'spread'=>0,'bottom'=>0],
            ];
        }

        // coverage: pi.total > 0 dianggap "punya output"
        $validCnt = $pis->filter(fn($v) => $v > 0)->count();
        $coverage = $n > 0 ? ($validCnt / $n) : 0.0;

        // spread: stddev
        $mean = $pis->avg();
        $var = $pis->map(fn($v) => pow($v - $mean, 2))->avg();
        $std = sqrt((float)$var);

        // bottom avg: avg bottom 3
        $bottomAvg = $pis->sort()->take(min(3, $n))->avg();

        // ---- scoring bands (1..5)
        $scoreCoverage = match (true) {
            $coverage >= 0.95 => 5,
            $coverage >= 0.85 => 4,
            $coverage >= 0.70 => 3,
            $coverage >= 0.50 => 2,
            default => 1,
        };

        $scoreSpread = match (true) { // makin kecil makin bagus
            $std <= 0.20 => 5,
            $std <= 0.40 => 4,
            $std <= 0.70 => 3,
            $std <= 1.00 => 2,
            default => 1,
        };

        $scoreBottom = match (true) {
            $bottomAvg >= 4.0 => 5,
            $bottomAvg >= 3.0 => 4,
            $bottomAvg >= 2.0 => 3,
            $bottomAvg >= 1.2 => 2,
            default => 1,
        };

        // ---- aggregate
        $si = (0.40 * $scoreCoverage) + (0.35 * $scoreSpread) + (0.25 * $scoreBottom);
        $si = round($si, 2);

        return [
            'score' => $si,
            'coverage' => round($coverage * 100, 2), // %
            'spread' => round($std, 2),
            'bottom_avg' => round((float)$bottomAvg, 2),
            'parts' => [
                'coverage' => $scoreCoverage,
                'spread' => $scoreSpread,
                'bottom' => $scoreBottom,
            ],
        ];
    }
}