<?php

namespace App\Services\Dashboard;

class DekonInsightEngine
{
    public function generate($row, $prevRow = null): array
    {
        if (!$row) return [];

        $insights = [];

        $os = (float) $row->total_os;
        $npl = (float) $row->npl_pct;
        $restr = (float) $row->restr_os;
        $dpd12 = (float) $row->dpd12_os;

        if ($prevRow) {
            $prevOs = (float) $prevRow->total_os;

            if ($prevOs > 0) {
                $delta = (($os - $prevOs) / $prevOs) * 100;

                $insights[] = [
                    'type' => $delta >= 0 ? 'positive' : 'warning',
                    'title' => 'Pergerakan Outstanding',
                    'text' => sprintf(
                        'Outstanding kredit saat ini Rp %s, %s %.2f%% dibanding bulan lalu.',
                        number_format($os,0,',','.'),
                        $delta >= 0 ? 'naik' : 'turun',
                        abs($delta)
                    ),
                ];
            }
        }

        if ($npl >= 5) {
            $insights[] = [
                'type'=>'danger',
                'title'=>'Kualitas Kredit',
                'text'=>"Rasio NPL berada pada level ".number_format($npl,2,',','.')."% dan masih di atas threshold 5%.",
            ];
        }

        if ($os > 0 && $restr > 0) {
            $pct = ($restr/$os)*100;

            $insights[] = [
                'type'=>'warning',
                'title'=>'Restrukturisasi',
                'text'=>"Portofolio restrukturisasi mencapai ".number_format($pct,2,',','.')."% dari total OS.",
            ];
        }

        if ($dpd12 > 0) {
            $insights[]=[
                'type'=>'danger',
                'title'=>'Aging >360 Hari',
                'text'=>"Eksposur DPD >360 hari tercatat Rp ".number_format($dpd12,0,',','.').".",
            ];
        }

        return $insights;
    }
}