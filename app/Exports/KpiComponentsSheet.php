<?php

namespace App\Exports;

use App\Services\Kpi\KpiSummaryService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class KpiComponentsSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(
        public string $rawPeriod,
        public array $filters = []
    ) {}

    public function title(): string
    {
        return 'COMPONENTS';
    }

    public function headings(): array
    {
        return [
            'level','role','name','period','mode','score_total','ach','ref_user_id','ref_ao_code',
            'component_label','component_kind','value','target','weight','component_score','note',
        ];
    }

    public function array(): array
    {
        /** @var KpiSummaryService $svc */
        $svc  = app(KpiSummaryService::class);
        $rows = $svc->build($this->rawPeriod, $this->filters);

        $out = [];

        foreach (($rows ?? []) as $r) {

            $componentsRaw = $r['detail']['components'] ?? [];
            $components    = $this->normalizeComponents($componentsRaw);

            // kalau tidak ada komponen: tetap buat 1 row summary
            if (count($components) === 0) {
                $out[] = [
                    $r['level'] ?? null,
                    $r['role'] ?? null,
                    $r['name'] ?? null,
                    $r['period'] ?? null,
                    $r['mode'] ?? null,
                    (float)($r['score'] ?? 0),
                    (float)($r['ach'] ?? 0),
                    (int)($r['ref']['user_id'] ?? 0),
                    (string)($r['ref']['ao_code'] ?? ''),
                    null, null, null, null, null, null, null,
                ];
                continue;
            }

            // kalau ada komponen: 1 row per komponen
            foreach ($components as $c) {
                $val    = array_key_exists('val', $c) ? $c['val'] : null;
                $target = array_key_exists('target', $c) ? $c['target'] : null;
                $w      = array_key_exists('w', $c) ? $c['w'] : null;
                $score  = array_key_exists('score', $c) ? $c['score'] : null;

                $out[] = [
                    $r['level'] ?? null,
                    $r['role'] ?? null,
                    $r['name'] ?? null,
                    $r['period'] ?? null,
                    $r['mode'] ?? null,
                    (float)($r['score'] ?? 0),
                    (float)($r['ach'] ?? 0),
                    (int)($r['ref']['user_id'] ?? 0),
                    (string)($r['ref']['ao_code'] ?? ''),

                    $c['label'] ?? null,
                    $c['kind'] ?? null,
                    is_null($val) ? null : (float)$val,
                    is_null($target) ? null : (float)$target,
                    is_null($w) ? null : (float)$w,
                    is_null($score) ? null : (float)$score,
                    $c['note'] ?? null,
                ];
            }
        }

        return $out;
    }

    private function normalizeComponents($componentsRaw): array
    {
        if ($componentsRaw === null) return [];

        // list: [ ['label'=>...], ... ]
        if (is_array($componentsRaw) && $this->isList($componentsRaw)) {
            $out = [];
            foreach ($componentsRaw as $c) {
                if (is_array($c)) {
                    $out[] = $c;
                } elseif (is_scalar($c)) {
                    $out[] = ['label' => null, 'kind' => null, 'val' => (float)$c];
                }
            }
            return $out;
        }

        // assoc: ['os_turun'=>123, 'migrasi_pct'=>4.5]
        if (is_array($componentsRaw) && !$this->isList($componentsRaw)) {
            $out = [];
            foreach ($componentsRaw as $k => $v) {
                if (is_array($v)) {
                    $out[] = array_merge(['label' => (string)$k], $v);
                } else {
                    $out[] = [
                        'label' => (string)$k,
                        'kind'  => null,
                        'val'   => is_null($v) ? null : (float)$v,
                        'target'=> null,
                        'w'     => null,
                        'score' => null,
                        'note'  => null,
                    ];
                }
            }
            return $out;
        }

        // scalar
        if (is_scalar($componentsRaw)) {
            return [[
                'label' => null,
                'kind'  => null,
                'val'   => (float)$componentsRaw,
                'target'=> null,
                'w'     => null,
                'score' => null,
                'note'  => null,
            ]];
        }

        return [];
    }

    private function isList(array $arr): bool
    {
        if (function_exists('array_is_list')) return array_is_list($arr);

        $i = 0;
        foreach ($arr as $k => $_) {
            if ($k !== $i) return false;
            $i++;
        }
        return true;
    }
}