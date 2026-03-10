<?php

namespace App\Services\Dashboard;

class DekonRiskRadarService
{
    public function evaluate($row, $prevRow = null): array
    {
        if (!$row) {
            return [
                'score' => 0,
                'level' => 'watch',
                'items' => [],
                'headline' => 'Data radar risiko belum tersedia.',
            ];
        }

        $items = [];

        $totalOs   = (float) ($row->total_os ?? 0);
        $nplPct    = (float) ($row->npl_pct ?? 0);
        $restrOs   = (float) ($row->restr_os ?? 0);
        $dpd12Os   = (float) ($row->dpd12_os ?? 0);
        $targetYtd = (float) ($row->target_ytd ?? 0);
        $actualYtd = (float) ($row->realisasi_ytd ?? 0);

        // 1) NPL absolute
        if ($nplPct > 10) {
            $items[] = $this->item(
                'critical',
                'NPL berada jauh di atas threshold sehat.',
                'NPL saat ini tercatat ' . number_format($nplPct, 2, ',', '.') . '% dan memerlukan pengawasan intensif.'
            );
        } elseif ($nplPct > 5) {
            $items[] = $this->item(
                'high',
                'NPL masih di atas threshold internal.',
                'NPL saat ini tercatat ' . number_format($nplPct, 2, ',', '.') . '%.'
            );
        }

        // 2) NPL delta
        if ($prevRow) {
            $prevNplPct = (float) ($prevRow->npl_pct ?? 0);
            $deltaNpl = $nplPct - $prevNplPct;

            if ($deltaNpl > 2) {
                $items[] = $this->item(
                    'critical',
                    'NPL melonjak signifikan dibanding bulan lalu.',
                    'Kenaikan mencapai ' . number_format($deltaNpl, 2, ',', '.') . ' p.p.'
                );
            } elseif ($deltaNpl > 1) {
                $items[] = $this->item(
                    'high',
                    'NPL meningkat dibanding bulan lalu.',
                    'Kenaikan mencapai ' . number_format($deltaNpl, 2, ',', '.') . ' p.p.'
                );
            }
        }

        // 3) DPD 12
        if ($totalOs > 0) {
            $dpd12Pct = ($dpd12Os / $totalOs) * 100;

            if ($dpd12Pct > 20) {
                $items[] = $this->item(
                    'critical',
                    'Eksposur DPD > 12 bulan sangat tinggi.',
                    'Porsinya mencapai ' . number_format($dpd12Pct, 2, ',', '.') . '% dari total OS.'
                );
            } elseif ($dpd12Pct > 10) {
                $items[] = $this->item(
                    'high',
                    'Eksposur DPD > 12 bulan perlu perhatian.',
                    'Porsinya mencapai ' . number_format($dpd12Pct, 2, ',', '.') . '% dari total OS.'
                );
            }
        }

        // 4) Restrukturisasi
        if ($totalOs > 0) {
            $restrPct = ($restrOs / $totalOs) * 100;

            if ($restrPct > 40) {
                $items[] = $this->item(
                    'critical',
                    'Konsentrasi restrukturisasi sangat tinggi.',
                    'Restrukturisasi mencapai ' . number_format($restrPct, 2, ',', '.') . '% dari total OS.'
                );
            } elseif ($restrPct > 30) {
                $items[] = $this->item(
                    'high',
                    'Portofolio restrukturisasi tinggi.',
                    'Restrukturisasi mencapai ' . number_format($restrPct, 2, ',', '.') . '% dari total OS.'
                );
            } elseif ($restrPct > 20) {
                $items[] = $this->item(
                    'medium',
                    'Portofolio restrukturisasi cukup dominan.',
                    'Restrukturisasi mencapai ' . number_format($restrPct, 2, ',', '.') . '% dari total OS.'
                );
            }
        }

        // 5) Achievement risk
        if ($targetYtd > 0) {
            $ach = ($actualYtd / $targetYtd) * 100;

            if ($ach < 60) {
                $items[] = $this->item(
                    'critical',
                    'Realisasi YTD jauh di bawah target.',
                    'Pencapaian baru ' . number_format($ach, 2, ',', '.') . '% dari target.'
                );
            } elseif ($ach < 80) {
                $items[] = $this->item(
                    'high',
                    'Realisasi YTD masih di bawah ekspektasi.',
                    'Pencapaian baru ' . number_format($ach, 2, ',', '.') . '% dari target.'
                );
            }
        }

        // 6) OS contraction
        if ($prevRow) {
            $prevOs = (float) ($prevRow->total_os ?? 0);
            if ($prevOs > 0) {
                $deltaOsPct = (($totalOs - $prevOs) / $prevOs) * 100;

                if ($deltaOsPct < -5) {
                    $items[] = $this->item(
                        'high',
                        'Outstanding turun cukup dalam dibanding bulan lalu.',
                        'Penurunan mencapai ' . number_format(abs($deltaOsPct), 2, ',', '.') . '%.'
                    );
                } elseif ($deltaOsPct < -3) {
                    $items[] = $this->item(
                        'medium',
                        'Outstanding mengalami kontraksi bulanan.',
                        'Penurunan mencapai ' . number_format(abs($deltaOsPct), 2, ',', '.') . '%.'
                    );
                }
            }
        }

        $score = $this->calculateScore($items);
        $level = $this->resolveLevel($score);

        return [
            'score' => $score,
            'level' => $level,
            'headline' => $this->headline($level),
            'items' => $items,
        ];
    }

    protected function item(string $severity, string $title, string $desc): array
    {
        return [
            'severity' => $severity,
            'title' => $title,
            'desc' => $desc,
        ];
    }

    protected function calculateScore(array $items): int
    {
        $score = 0;

        foreach ($items as $item) {
            $score += match ($item['severity']) {
                'critical' => 30,
                'high'     => 20,
                'medium'   => 10,
                default    => 5,
            };
        }

        return min($score, 100);
    }

    protected function resolveLevel(int $score): string
    {
        return match (true) {
            $score >= 80 => 'critical',
            $score >= 55 => 'high',
            $score >= 30 => 'medium',
            default      => 'watch',
        };
    }

    protected function headline(string $level): string
    {
        return match ($level) {
            'critical' => 'Radar risiko menunjukkan tekanan tinggi pada kualitas portofolio.',
            'high'     => 'Radar risiko menunjukkan beberapa area yang perlu perhatian serius.',
            'medium'   => 'Radar risiko menunjukkan tekanan moderat yang perlu dipantau.',
            default    => 'Radar risiko berada pada level pemantauan.',
        };
    }
}