<?php

namespace App\Services\Kpi;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class KpiSummaryService
{
    public function normalizePeriodLabel(string $raw): string
    {
        if ($raw === '') return now()->format('Y-m');
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) return $raw;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return Carbon::parse($raw)->format('Y-m');
        return now()->format('Y-m');
    }

    public function normalizePeriodDate(string $raw): string
    {
        // output: YYYY-MM-01
        if ($raw === '') return now()->startOfMonth()->toDateString();
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) return Carbon::createFromFormat('Y-m', $raw)->startOfMonth()->toDateString();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return Carbon::parse($raw)->startOfMonth()->toDateString();
        return now()->startOfMonth()->toDateString();
    }

    private array $levelRoleMap = [
    'STAFF' => ['RO','SO','AO','FE','BE'],
    'TL'    => ['TLRO','TLUM','TLFE'],
    'KASI'  => ['KSLR','KSBE','KSFE'],
    'KABAG' => ['KBL'],
    ];

    private function userRoleExpr(string $alias = 'u'): string
    {
        // role-code = RO/TLRO/TLUM/TLFE/KSLR/KSBE/KSFE/KBL dst
        // level & level_role isinya sama, tapi kita tetap bikin fallback yang aman.
        return "UPPER(COALESCE(NULLIF({$alias}.level_role,''), NULLIF({$alias}.level,'')))";
    }

    private function applyUserRoleFilter($qb, string $roleCode, string $alias = 'u')
    {
        $expr = $this->userRoleExpr($alias);
        return $qb->whereRaw("{$expr} = ?", [strtoupper($roleCode)]);
    }

    /**
 * Build universal KPI summary rows.
 * Start: RO (staff) + TLRO (TL) + KBL (Kabag)
 * Next: SO/FE/BE/AO + TLUM/TLFE
 * Now: KSLR + KSBE + KSFE (KASI)
 *
 * ✅ Improvement:
 * - Map-driven: semua TL & KASI muncul otomatis sesuai daftar builder.
 * - Role filter tetap jalan (ALL atau spesifik).
 * - Aman kalau builder belum ada (method_exists).
 */
public function build(string $rawPeriod, array $filters = []): array
{
    $periodYmd = $this->normalizePeriodDate($rawPeriod);
    $periodYm  = Carbon::parse($periodYmd)->format('Y-m');

    $level = strtoupper($filters['level'] ?? 'ALL'); // STAFF/TL/KASI/KABAG/ALL
    $role  = strtoupper($filters['role']  ?? 'ALL'); // RO/TLRO/KSLR/ALL
    $q     = trim((string)($filters['q'] ?? ''));

    // ✅ Daftar builder per level (tinggal tambah kalau ada role baru)
    // key = ROLE, value = method builder di service ini
    $levelRoleMap = [
        'STAFF' => [
            'RO' => 'buildStaffRo',
            'FE' => 'buildStaffFe',
            'BE' => 'buildStaffBe',
            'AO' => 'buildStaffAo',
            'SO' => 'buildStaffSo',
        ],
        'TL' => [
            'TLRO' => 'buildTlro',
            'TLUM' => 'buildTlum',
            'TLFE' => 'buildTlfe',

            // ✅ kalau ada TL lain, buka ini:
            // 'TLSO' => 'buildTlso',
            // 'TLBE' => 'buildTlbe',
        ],
        'KASI' => [
            'KSLR' => 'buildKslr',
            'KSBE' => 'buildKsbe',
            'KSFE' => 'buildKsfe',

            // ✅ kalau ada KASI lain, buka ini:
            // 'KSLU' => 'buildKslu',
            // 'KSO'  => 'buildKso',
        ],
        'KABAG' => [
            'KBL' => 'buildKbl',
        ],
    ];

    // helper: execute semua role builder pada 1 level sesuai filter role
    $rows = [];
    $runLevel = function (string $lvlKey) use (&$rows, $levelRoleMap, $role, $periodYmd, $periodYm, $q) {
        $roleMap = $levelRoleMap[$lvlKey] ?? [];
        foreach ($roleMap as $rKey => $method) {

            // filter role
            if ($role !== 'ALL' && $role !== $rKey) continue;

            // safety: kalau method belum ada, skip (biar tidak fatal)
            if (!method_exists($this, $method)) continue;

            $rows = array_merge($rows, $this->{$method}($periodYmd, $periodYm, $q));
        }
    };

    // STAFF
    if ($level === 'ALL' || $level === 'STAFF') {
        $runLevel('STAFF');
    }

    // TL
    if ($level === 'ALL' || $level === 'TL') {
        $runLevel('TL');
    }

    // KASI
    if ($level === 'ALL' || $level === 'KASI') {
        $runLevel('KASI');
    }

    // KABAG
    if ($level === 'ALL' || $level === 'KABAG') {
        $runLevel('KABAG');
    }

    \Log::info('KPI SUMMARY before dedupe', [
        'period' => $periodYmd,
        'count'  => count($rows),
        'levels' => array_count_values(array_map(fn($x)=>$x['level'] ?? 'NULL', $rows)),
        'roles'  => array_count_values(array_map(fn($x)=>$x['role'] ?? 'NULL', $rows)),
    ]);

    // optional: anti-duplikat
    $rows = $this->dedupeRows($rows);

    \Log::info('KPI SUMMARY after dedupe', [
        'period' => $periodYmd,
        'count'  => count($rows),
        'levels' => array_count_values(array_map(fn($x)=>$x['level'] ?? 'NULL', $rows)),
        'roles'  => array_count_values(array_map(fn($x)=>$x['role'] ?? 'NULL', $rows)),
    ]);

    // sort: KABAG -> KASI -> TL -> STAFF, lalu score desc
    $levelOrder = ['KABAG'=>1,'KASI'=>2,'TL'=>3,'STAFF'=>4];
    usort($rows, function($a, $b) use ($levelOrder) {
        $la = $levelOrder[$a['level']] ?? 99;
        $lb = $levelOrder[$b['level']] ?? 99;
        if ($la !== $lb) return $la <=> $lb;
        return ((float)($b['score'] ?? 0)) <=> ((float)($a['score'] ?? 0));
    });

    // rank per group (level+role)
    return $this->applyRank($rows);
}

    private function applyRank(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $idx => $r) {
            $k = ($r['level'] ?? '-') . '|' . ($r['role'] ?? '-');
            $grouped[$k][] = $idx;
        }

        foreach ($grouped as $k => $indexes) {
            usort($indexes, fn($i,$j) => ((float)($rows[$j]['score'] ?? 0)) <=> ((float)($rows[$i]['score'] ?? 0)));
            $rank = 1;
            foreach ($indexes as $i) {
                $rows[$i]['rank'] = $rank++;
            }
        }
        return $rows;
    }

    // ============================
    // STAFF RO  (table: kpi_ro_monthly)
    // ============================
    private function buildStaffRo(string $periodYmd, string $periodYm, string $q): array
    {
        $qb = DB::table('kpi_ro_monthly as k')
            ->join('users as u', 'u.ao_code', '=', 'k.ao_code')
            ->whereDate('k.period_month', $periodYmd);

        // ✅ role filter pakai helper (users.level/level_role)
        $this->applyUserRoleFilter($qb, 'RO', 'u');

        if ($q !== '') {
            $qb->where(function ($w) use ($q) {
                $w->where('u.name', 'like', "%{$q}%")
                ->orWhere('k.ao_code', 'like', "%{$q}%");
            });
        }

        $rows = $qb->select([
                DB::raw("'STAFF' as level"),
                DB::raw("'RO' as role"),
                'u.name as name',
                DB::raw("NULL as unit"),
                DB::raw("NULL as scope_count"),
                DB::raw("'" . $periodYm . "' as period"),
                DB::raw("UPPER(COALESCE(k.calc_mode,'realtime')) as mode"),
                DB::raw("COALESCE(k.total_score_weighted,0) as score"),
                DB::raw("ROUND( (COALESCE(k.topup_pct,0)+COALESCE(k.repayment_pct,0)+COALESCE(k.noa_pct,0)+(100-COALESCE(k.dpk_pct,0))) / 4 , 2) as ach"),
                DB::raw("JSON_OBJECT(
                    'baseline_ok', COALESCE(k.baseline_ok,0),
                    'baseline_note', k.baseline_note,
                    'start_snapshot_month', k.start_snapshot_month,
                    'end_snapshot_month', k.end_snapshot_month,
                    'calc_source_position_date', k.calc_source_position_date,
                    'locked_at', k.locked_at
                ) as meta_json"),

                'k.ao_code',
                'k.topup_realisasi','k.topup_target','k.topup_pct','k.topup_score',
                'k.topup_cif_count','k.topup_cif_new_count','k.topup_max_cif_amount',
                'k.topup_concentration_pct','k.topup_top3_json','k.topup_adj_json',

                'k.repayment_rate','k.repayment_pct','k.repayment_score',
                'k.repayment_total_os','k.repayment_os_lancar',

                'k.noa_realisasi','k.noa_target','k.noa_pct','k.noa_score',

                'k.dpk_pct','k.dpk_score','k.dpk_migrasi_count','k.dpk_migrasi_os','k.dpk_total_os_akhir',

                DB::raw("u.id as ref_user_id"),
            ])
            ->orderByDesc('k.total_score_weighted')
            ->limit(500)
            ->get();

        return $rows->map(function ($r) {

            $meta = $this->safeJson($r->meta_json ?? null);

            $top3 = $this->safeJson($r->topup_top3_json ?? null);
            $adj  = $this->safeJson($r->topup_adj_json ?? null);

            // ✅ audit harus didefinisikan
            $audit = [
                'baseline_ok' => (int)($meta['baseline_ok'] ?? 0),
                'baseline_note' => $meta['baseline_note'] ?? null,
                'start_snapshot_month' => $meta['start_snapshot_month'] ?? null,
                'end_snapshot_month' => $meta['end_snapshot_month'] ?? null,
                'calc_source_position_date' => $meta['calc_source_position_date'] ?? null,
                'locked_at' => $meta['locked_at'] ?? null,
            ];

            // ✅ components harus jadi variable
            $components = [
                [
                    'label'  => 'TOPUP',
                    'kind'   => 'rp',
                    'val'    => (float)($r->topup_realisasi ?? 0),
                    'target' => (float)($r->topup_target ?? 0),
                    'w'      => null,
                    'score'  => (float)($r->topup_score ?? 0),
                    'note'   => 'CIF: '.(int)($r->topup_cif_count ?? 0).', New: '.(int)($r->topup_cif_new_count ?? 0),
                    'extra'  => [
                        'max_cif_amount' => (float)($r->topup_max_cif_amount ?? 0),
                        'concentration_pct' => (float)($r->topup_concentration_pct ?? 0),
                        'top3' => $top3,
                        'adj'  => $adj,
                    ],
                ],
                [
                    'label'  => 'REPAYMENT',
                    'kind'   => 'pct',
                    'val'    => (float)($r->repayment_rate ?? 0),
                    'target' => null,
                    'w'      => null,
                    'score'  => (float)($r->repayment_score ?? 0),
                    'note'   => 'OS Lancar: Rp '.number_format((float)($r->repayment_os_lancar ?? 0), 0, ',', '.'),
                    'extra'  => [
                        'pct' => (float)($r->repayment_pct ?? 0),
                        'total_os' => (float)($r->repayment_total_os ?? 0),
                    ],
                ],
                [
                    'label'  => 'NOA',
                    'kind'   => 'int',
                    'val'    => (int)($r->noa_realisasi ?? 0),
                    'target' => (int)($r->noa_target ?? 0),
                    'w'      => null,
                    'score'  => (float)($r->noa_score ?? 0),
                    'extra'  => [
                        'pct' => (float)($r->noa_pct ?? 0),
                    ],
                ],
                [
                    'label'  => 'DPK',
                    'kind'   => 'pct',
                    'val'    => (float)($r->dpk_pct ?? 0),
                    'target' => null,
                    'w'      => null,
                    'score'  => (float)($r->dpk_score ?? 0),
                    'note'   => 'Migrasi: Rp '.number_format((float)($r->dpk_migrasi_os ?? 0), 0, ',', '.'),
                    'extra'  => [
                        'migrasi_count' => (int)($r->dpk_migrasi_count ?? 0),
                        'total_os_akhir'=> (float)($r->dpk_total_os_akhir ?? 0),
                    ],
                ],
            ];

            // ✅ baru return
            return [
                'level' => $r->level,
                'role'  => $r->role,
                'name'  => $r->name,
                'unit'  => null,
                'scope_count' => null,
                'period'=> $r->period,
                'mode'  => strtoupper((string)($r->mode ?? '')),
                'score' => (float)($r->score ?? 0),
                'ach'   => (float)($r->ach ?? 0),
                'rank'  => null,
                'risk_badge' => null,
                'delta_pp'   => null,

                'detail' => [
                    'components' => $components,
                    'audit'      => $audit,
                ],

                'ref' => [
                    'user_id' => (int)($r->ref_user_id ?? 0),
                    'ao_code' => (string)($r->ao_code ?? ''),
                ],
            ];

        })->values()->all();
    }

    private function buildStaffFe(string $periodYmd, string $periodYm, string $q): array
    {
        $qb = DB::table('kpi_fe_monthlies as k')
            ->join('users as u', 'u.id', '=', 'k.fe_user_id')
            ->whereDate('k.period', $periodYmd);

        // ✅ role filter pakai helper (users.level / users.level_role)
        $this->applyUserRoleFilter($qb, 'FE', 'u');

        if ($q !== '') {
            $qb->where(function ($w) use ($q) {
                $w->where('u.name', 'like', "%{$q}%")
                ->orWhere('k.ao_code', 'like', "%{$q}%");
            });
        }

        $rows = $qb->select([
                DB::raw("'STAFF' as level"),
                DB::raw("'FE' as role"),
                'u.name as name',
                DB::raw("NULL as unit"),
                DB::raw("NULL as scope_count"),
                DB::raw("'" . $periodYm . "' as period"),
                DB::raw("UPPER(COALESCE(k.calc_mode,'realtime')) as mode"),
                DB::raw("COALESCE(k.total_score_weighted,0) as score"),
                DB::raw("COALESCE(k.ach_os_turun_pct,0) as ach"),
                DB::raw("JSON_OBJECT(
                    'baseline_ok', COALESCE(k.baseline_ok,1),
                    'baseline_note', k.baseline_note,
                    'calculated_at', k.calculated_at
                ) as meta_json"),

                // ✅ dipakai di filter/search + ref
                'k.ao_code',

                // ✅ dipakai di detail (biar gak 0 terus)
                'k.os_kol2_turun_murni',
                'k.ach_migrasi_pct',
                'k.penalty_paid_total',

                DB::raw("u.id as ref_user_id"),
            ])
            ->orderByDesc('k.total_score_weighted')
            ->limit(500)
            ->get();

        return $rows->map(function ($r) {
            $meta = $this->safeJson($r->meta_json ?? null);

            return [
                'level' => $r->level,
                'role'  => $r->role,
                'name'  => $r->name,
                'unit'  => null,
                'scope_count' => null,
                'period'=> $r->period,
                'mode'  => strtoupper((string)($r->mode ?? '')),
                'score' => (float)($r->score ?? 0),
                'ach'   => (float)($r->ach ?? 0),
                'rank'  => null,
                'risk_badge' => $meta['risk_badge'] ?? null,
                'delta_pp'   => $meta['delta']['pp'] ?? null,
                'detail' => [
                    'components' => [
                        'os_turun'  => (float)($r->os_kol2_turun_murni ?? 0),
                        'migrasi_pct' => (float)($r->ach_migrasi_pct ?? 0),
                        'penalty_paid_total' => (float)($r->penalty_paid_total ?? 0),
                    ],
                    'audit' => $meta,
                ],
                'ref' => [
                    'user_id' => (int)($r->ref_user_id ?? 0),
                    'ao_code' => (string)($r->ao_code ?? ''),
                ],
            ];
        })->values()->all();
    }

    private function buildStaffBe(string $periodYmd, string $periodYm, string $q): array
    {
        $qb = DB::table('kpi_be_monthlies as k')
            ->join('users as u', 'u.id', '=', 'k.be_user_id')
            ->whereRaw("DATE_FORMAT(k.period, '%Y-%m') = ?", [$periodYm]);

        // ✅ pakai helper (karena users tidak punya kolom u.role)
        $this->applyUserRoleFilter($qb, 'BE', 'u');

        if ($q !== '') {
            $qb->where('u.name', 'like', "%{$q}%");
        }

        $rows = $qb->select([
                DB::raw("'STAFF' as level"),
                DB::raw("'BE' as role"),
                'u.name as name',
                DB::raw("NULL as unit"),
                DB::raw("NULL as scope_count"),
                DB::raw("'" . $periodYm . "' as period"),
                DB::raw("
                    CASE
                        WHEN k.status = 'approved' OR k.approved_at IS NOT NULL THEN 'EOM'
                        ELSE 'REALTIME'
                    END as mode
                "),

                // ✅ FIX: ambil TOTAL PI dulu baru fallback final_score
                DB::raw("COALESCE(k.total_pi, k.final_score, 0) as score"),

                // ach tetap recovery_pct (atau nanti bisa kamu bikin composite)
                DB::raw("COALESCE(k.recovery_pct,0) as ach"),

                'k.recovery_principal',
                'k.actual_os_selesai',
                'k.actual_noa_selesai',
                'k.actual_bunga_masuk',
                'k.actual_denda_masuk',
                'k.total_pi',

                DB::raw("JSON_OBJECT(
                    'status', k.status,
                    'approval_note', k.approval_note,
                    'submitted_at', k.submitted_at,
                    'approved_at', k.approved_at,
                    'approved_by', k.approved_by
                ) as meta_json"),
                DB::raw("u.id as ref_user_id"),
            ])
            ->orderByRaw('COALESCE(k.total_pi, k.final_score, 0) DESC')
            ->limit(500)
            ->get();

        return $rows->map(function ($r) {
            $meta = $this->safeJson($r->meta_json ?? null);

            return [
                'level' => $r->level,
                'role'  => $r->role,
                'name'  => $r->name,
                'unit'  => null,
                'scope_count' => null,
                'period'=> $r->period,
                'mode'  => strtoupper((string)($r->mode ?? 'EOM')),
                'score' => (float)($r->score ?? 0),
                'ach'   => (float)($r->ach ?? 0),
                'rank'  => null,
                'risk_badge' => null,
                'delta_pp'   => null,
                'detail' => [
                    'components' => [
                        ['label'=>'Recovery Principal','val'=>(float)($r->recovery_principal ?? 0),'target'=>null,'w'=>null,'score'=>null],
                        ['label'=>'OS Selesai','val'=>(float)($r->actual_os_selesai ?? 0),'target'=>null,'w'=>null,'score'=>null],
                        ['label'=>'NOA Selesai','val'=>(int)($r->actual_noa_selesai ?? 0),'target'=>null,'w'=>null,'score'=>null],
                        ['label'=>'Bunga Masuk','val'=>(float)($r->actual_bunga_masuk ?? 0),'target'=>null,'w'=>null,'score'=>null],
                        ['label'=>'Denda Masuk','val'=>(float)($r->actual_denda_masuk ?? 0),'target'=>null,'w'=>null,'score'=>null],
                        ['label'=>'Total PI','val'=>(float)($r->total_pi ?? 0),'target'=>null,'w'=>null,'score'=>null],
                    ],
                    'audit' => $meta,
                ],
                'ref' => ['user_id' => (int)($r->ref_user_id ?? 0)],
            ];
        })->values()->all();
    }

    private function buildStaffAo(string $periodYmd, string $periodYm, string $q): array
    {
        $qb = DB::table('kpi_ao_monthlies as k')
            ->join('users as u', 'u.id', '=', 'k.user_id')
            ->whereDate('k.period', $periodYmd);

        $this->applyUserRoleFilter($qb, 'AO', 'u'); // opsional kalau ada

        if ($q !== '') {
            $qb->where(function($w) use ($q){
                $w->where('u.name', 'like', "%$q%")
                ->orWhere('k.ao_code', 'like', "%$q%");
            });
        }

        $rows = $qb->select([
                DB::raw("'STAFF' as level"),
                DB::raw("'AO' as role"),
                'u.name as name',
                DB::raw("NULL as unit"),
                DB::raw("NULL as scope_count"),
                DB::raw("'" . $periodYm . "' as period"),
                DB::raw("UPPER(COALESCE(k.data_source,'live')) as mode"),
                DB::raw("COALESCE(k.score_total,0) as score"),
                DB::raw("COALESCE(k.rr_pct,0) as ach"),

                // ===== ambil semua field breakdown =====
                'k.ao_code',
                'k.os_growth',
                'k.noa_growth',
                'k.rr_pct',
                'k.npl_migration_pct',
                'k.score_os',
                'k.score_noa',
                'k.score_rr',
                'k.score_kolek',

                DB::raw("JSON_OBJECT(
                    'data_source', k.data_source,
                    'scheme', k.scheme,
                    'calculated_at', k.calculated_at
                ) as meta_json"),
                DB::raw("u.id as ref_user_id"),
            ])
            ->orderByDesc('k.score_total')
            ->limit(500)
            ->get();

        return $rows->map(function($r){
            $meta = $this->safeJson($r->meta_json ?? null);

            $components = [
                [
                    'label' => 'OS_GROWTH',
                    'kind'  => 'rp',
                    'val'   => (float)($r->os_growth ?? 0),
                    'target'=> null,
                    'w'     => null,
                    'score' => (float)($r->score_os ?? 0),
                ],
                [
                    'label' => 'NOA_GROWTH',
                    'kind'  => 'int',
                    'val'   => (int)($r->noa_growth ?? 0),
                    'target'=> null,
                    'w'     => null,
                    'score' => (float)($r->score_noa ?? 0),
                ],
                [
                    'label' => 'RR_PCT',
                    'kind'  => 'pct',
                    'val'   => (float)($r->rr_pct ?? 0),
                    'target'=> null,
                    'w'     => null,
                    'score' => (float)($r->score_rr ?? 0),
                ],
                [
                    'label' => 'NPL_MIGRATION_PCT',
                    'kind'  => 'pct',
                    'val'   => (float)($r->npl_migration_pct ?? 0),
                    'target'=> null,
                    'w'     => null,
                    'score' => (float)($r->score_kolek ?? 0),
                ],
            ];

            return [
                'level' => $r->level,
                'role'  => $r->role,
                'name'  => $r->name,
                'unit'  => null,
                'scope_count' => null,
                'period'=> $r->period,
                'mode'  => strtoupper((string)($r->mode ?? 'LIVE')),
                'score' => (float)($r->score ?? 0),
                'ach'   => (float)($r->ach ?? 0),
                'rank'  => null,
                'risk_badge' => null,
                'delta_pp'   => null,
                'detail' => [
                    'components' => $components,
                    'audit'      => $meta,
                ],
                'ref' => [
                    'user_id' => (int)($r->ref_user_id ?? 0),
                    'ao_code' => (string)($r->ao_code ?? ''),
                ],
            ];
        })->values()->all();
    }

    // ============================
    // STAFF SO  (table: kpi_so_monthlies)
    // ============================
    private function buildStaffSo(string $periodYmd, string $periodYm, string $q): array
    {
        $qb = DB::table('kpi_so_monthlies as k')
            ->join('users as u', 'u.id', '=', 'k.user_id')
            ->whereDate('k.period', $periodYmd);

        // kalau kamu punya filter role/level SO, pakai helper kamu
        // (ingat: di DB kamu level dan level_role sama, pilih salah satu yg konsisten)
        $this->applyUserRoleFilter($qb, 'SO', 'u'); // <- kalau helper ini benar

        if ($q !== '') {
            $qb->where(function ($w) use ($q) {
                $w->where('u.name', 'like', "%{$q}%")
                ->orWhere('k.ao_code', 'like', "%{$q}%");
            });
        }

        $rows = $qb->select([
                DB::raw("'STAFF' as level"),
                DB::raw("'SO' as role"),
                'u.name as name',
                DB::raw("NULL as unit"),
                DB::raw("NULL as scope_count"),
                DB::raw("'" . $periodYm . "' as period"),

                // mode sederhana
                DB::raw("CASE WHEN k.is_final = 1 THEN 'EOM' ELSE 'LIVE' END as mode"),

                DB::raw("COALESCE(k.score_total,0) as score"),

                // ach: optional. kalau belum punya rumus, set null / atau pakai rata2 pct yang ada
                // misal: activity_pct sudah ada (kolom activity_pct), rr_pct sudah ada
                DB::raw("ROUND( (COALESCE(k.rr_pct,0) + COALESCE(k.activity_pct,0)) / 2 , 2) as ach"),

                // ===== kolom komponen yg dipakai breakdown =====
                'k.ao_code',
                'k.os_disbursement',
                'k.os_adjustment',
                'k.rr_pct',
                'k.activity_target',
                'k.activity_actual',
                'k.activity_pct',

                // score per komponen
                'k.score_os',
                'k.score_noa',
                'k.score_rr',
                'k.score_activity',

                DB::raw("JSON_OBJECT(
                    'calculated_at', k.calculated_at,
                    'is_final', k.is_final
                ) as meta_json"),

                DB::raw("u.id as ref_user_id"),
            ])
            ->orderByDesc('k.score_total')
            ->limit(500)
            ->get();

        return $rows->map(function ($r) {
            $meta = $this->safeJson($r->meta_json ?? null);

            // ✅ components array harus berbentuk LIST of array (bukan associative)
            $components = [
                [
                    'label'  => 'OS_DISBURSEMENT',
                    'kind'   => 'rp',
                    'val'    => (float)($r->os_disbursement ?? 0),
                    'target' => null,
                    'w'      => null,
                    'score'  => (float)($r->score_os ?? 0),
                ],
                [
                    'label'  => 'OS_ADJUSTMENT',
                    'kind'   => 'rp',
                    'val'    => (float)($r->os_adjustment ?? 0),
                    'target' => null,
                    'w'      => null,
                    'score'  => null, // kalau adjustment punya score sendiri, isi
                ],
                [
                    'label'  => 'RR_PCT',
                    'kind'   => 'pct',
                    'val'    => (float)($r->rr_pct ?? 0),
                    'target' => 100.0, // kalau target rr default 100
                    'w'      => null,
                    'score'  => (float)($r->score_rr ?? 0),
                    'note'   => null,
                ],
                [
                    'label'  => 'ACTIVITY',
                    'kind'   => 'int',
                    'val'    => (int)($r->activity_actual ?? 0),
                    'target' => (int)($r->activity_target ?? 0),
                    'w'      => null,
                    'score'  => (float)($r->score_activity ?? 0),
                    'note'   => 'Pct: ' . number_format((float)($r->activity_pct ?? 0), 2, ',', '.') . '%',
                ],
            ];

            return [
                'level' => $r->level,
                'role'  => $r->role,
                'name'  => $r->name,
                'unit'  => null,
                'scope_count' => null,
                'period'=> $r->period,
                'mode'  => strtoupper((string)($r->mode ?? 'LIVE')),
                'score' => (float)($r->score ?? 0),
                'ach'   => (float)($r->ach ?? 0),
                'rank'  => null,
                'risk_badge' => null,
                'delta_pp'   => null,
                'detail' => [
                    'components' => $components,
                    'audit'      => $meta,
                ],
                'ref' => [
                    'user_id' => (int)($r->ref_user_id ?? 0),
                    'ao_code' => (string)($r->ao_code ?? ''),
                ],
            ];
        })->values()->all();
    }

    // ============================
    // TLRO (table: kpi_tlro_monthlies)
    // ============================
    private function buildTlro(string $periodYmd, string $periodYm, string $q): array
    {
        $qb = DB::table('kpi_tlro_monthlies as k')
            ->join('users as u', 'u.id', '=', 'k.tlro_id')
            ->whereRaw("DATE_FORMAT(k.period, '%Y-%m') = ?", [$periodYm]);

        if ($q !== '') {
            $qb->where('u.name', 'like', "%$q%");
        }

        $rows = $qb->select([
                DB::raw("'TL' as level"),
                DB::raw("'TLRO' as role"),
                'u.name as name',
                DB::raw("NULL as unit"),
                'k.ro_count as scope_count',
                DB::raw("'" . $periodYm . "' as period"),
                DB::raw("UPPER(COALESCE(k.calc_mode,'realtime')) as mode"),
                DB::raw("COALESCE(k.leadership_index,0) as score"),
                DB::raw("COALESCE(k.pi_scope,0) as ach"),
                'k.status_label',
                'k.meta as meta_json',
                DB::raw("u.id as ref_user_id"),
            ])
            ->orderByDesc('k.leadership_index')
            ->limit(200)
            ->get();

        return $rows->map(function($r){
            $meta = $this->safeJson($r->meta_json ?? null);

            return [
                'level' => $r->level,
                'role'  => $r->role,
                'name'  => $r->name,
                'unit'  => null,
                'scope_count' => (int)($r->scope_count ?? 0),
                'period'=> $r->period,
                'mode'  => strtoupper((string)$r->mode),
                'score' => (float)$r->score,
                'ach'   => (float)$r->ach,
                'rank'  => null,
                'risk_badge' => $meta['risk_badge'] ?? null,
                'delta_pp'   => $meta['delta']['pp'] ?? null,
                'detail' => [
                    'components' => [
                        'pi_scope'        => (float)($r->ach ?? 0),
                        'stability_index' => (float)($meta['stability_index'] ?? 0),
                        'risk_index'      => (float)($meta['risk_index'] ?? 0),
                        'improvement_index' => (float)($meta['improvement_index'] ?? 0),
                    ],
                    'audit' => $meta,
                ],
                'ref' => [
                    'user_id' => (int)$r->ref_user_id,
                ],
            ];
        })->values()->all();
    }

    // =========================
    // TLUM
    // =========================
    private function buildTlum(string $periodYmd, string $periodYm, string $q): array
    {
        $qb = DB::table('kpi_tlum_monthlies as k')
            ->join('users as u', 'u.id', '=', 'k.tlum_user_id')
            ->whereDate('k.period', $periodYmd)
            ->whereRaw("UPPER(COALESCE(u.level,'')) = 'TLUM'");

        if ($q !== '') {
            $qb->where(function ($w) use ($q) {
                $w->where('u.name', 'like', "%$q%")
                  ->orWhere('k.unit_code', 'like', "%$q%");
            });
        }

        $rows = $qb->select([
                DB::raw("'TL' as level"),
                DB::raw("'TLUM' as role"),
                'u.name as name',
                'k.unit_code as unit',
                DB::raw("(
                    SELECT COUNT(*)
                    FROM org_assignments oa
                    WHERE oa.leader_id = u.id
                    AND oa.is_active = 1
                    AND oa.effective_from <= '{$periodYmd}'
                    AND (oa.effective_to IS NULL OR oa.effective_to >= '{$periodYmd}')
                ) as scope_count"),
                DB::raw("'" . $periodYm . "' as period"),
                DB::raw("'REALTIME' as mode"),
                DB::raw("COALESCE(k.pi_total,0) as score"),
                DB::raw("
                    CASE
                        WHEN COALESCE(k.rr_target,0) > 0
                            THEN ROUND((COALESCE(k.rr_actual,0) / k.rr_target) * 100, 2)
                        ELSE 0
                    END as rr_pct_calc
                "),
                DB::raw("
                    ROUND(
                        (
                            COALESCE(k.noa_pct,0)
                        + COALESCE(k.os_pct,0)
                        + (
                            CASE
                                WHEN COALESCE(k.rr_target,0) > 0
                                    THEN (COALESCE(k.rr_actual,0) / k.rr_target) * 100
                                ELSE 0
                            END
                          )
                        + COALESCE(k.com_pct,0)
                        + COALESCE(k.day_pct,0)
                        ) / 5
                    , 2) as ach
                "),
                DB::raw("JSON_OBJECT(
                    'unit_code', k.unit_code,
                    'calculated_at', k.calculated_at
                ) as meta_json"),

                'k.noa_target','k.os_target','k.rr_target','k.com_target','k.day_target',
                'k.noa_actual','k.os_actual','k.rr_actual','k.com_actual','k.day_actual',
                'k.noa_pct','k.os_pct','k.com_pct','k.day_pct',
                'k.score_noa','k.score_os','k.score_rr','k.score_com',

                DB::raw("u.id as ref_user_id"),
            ])
            ->orderByDesc('k.pi_total')
            ->limit(200)
            ->get();

        $mapped = $rows->map(function ($r) {
            $meta = $this->safeJson($r->meta_json ?? null);
            $rrPctCalc = (float)($r->rr_pct_calc ?? 0);

            $components = [
                'noa' => [
                    'target' => (int)($r->noa_target ?? 0),
                    'actual' => (int)($r->noa_actual ?? 0),
                    'pct'    => (float)($r->noa_pct ?? 0),
                    'score'  => (float)($r->score_noa ?? 0),
                ],
                'os' => [
                    'target' => (float)($r->os_target ?? 0),
                    'actual' => (float)($r->os_actual ?? 0),
                    'pct'    => (float)($r->os_pct ?? 0),
                    'score'  => (float)($r->score_os ?? 0),
                ],
                'rr' => [
                    'target' => (float)($r->rr_target ?? 0),
                    'actual' => (float)($r->rr_actual ?? 0),
                    'pct'    => $rrPctCalc,
                    'score'  => (float)($r->score_rr ?? 0),
                ],
                'com' => [
                    'target' => (int)($r->com_target ?? 0),
                    'actual' => (int)($r->com_actual ?? 0),
                    'pct'    => (float)($r->com_pct ?? 0),
                    'score'  => (float)($r->score_com ?? 0),
                ],
                'day' => [
                    'target' => (int)($r->day_target ?? 0),
                    'actual' => (int)($r->day_actual ?? 0),
                    'pct'    => (float)($r->day_pct ?? 0),
                ],
            ];

            return [
                'level' => $r->level,
                'role'  => $r->role,
                'name'  => $r->name,
                'unit'  => $r->unit,
                'scope_count' => (int)($r->scope_count ?? 0),
                'period'=> $r->period,
                'mode'  => strtoupper((string)($r->mode ?? 'REALTIME')),
                'score' => (float)($r->score ?? 0),
                'ach'   => (float)($r->ach ?? 0),
                'rank'  => null,
                'risk_badge' => $meta['risk_badge'] ?? null,
                'delta_pp'   => $meta['delta']['pp'] ?? null,
                'detail' => [
                    'components' => $components,
                    'audit'      => $meta,
                ],
                'ref' => [
                    'user_id' => (int)($r->ref_user_id ?? 0),
                ],
            ];
        })->values();

        $unique = $mapped->unique(fn($x) => ($x['level'].'|'.$x['role'].'|'.($x['ref']['user_id'] ?? 0)))->values();
        return $unique->all();
    }

    // ============================
    // TLFE
    // ============================
    private function buildTlfe(string $periodYmd, string $periodYm, string $q): array
    {
        $qb = DB::table('kpi_tlfe_monthlies as k')
            ->join('users as u', 'u.id', '=', 'k.tlfe_id')
            ->whereDate('k.period', $periodYmd)
            ->whereRaw("UPPER(COALESCE(u.level,'')) = 'TLFE'");

        if ($q !== '') {
            $qb->where('u.name', 'like', "%$q%");
        }

        $rows = $qb->select([
                DB::raw("'TL' as level"),
                DB::raw("'TLFE' as role"),
                'u.name as name',
                DB::raw("NULL as unit"),
                'k.fe_count as scope_count',
                DB::raw("'{$periodYm}' as period"),
                DB::raw("UPPER(k.calc_mode) as mode"),
                DB::raw("COALESCE(k.leadership_index,0) as score"),
                DB::raw("ROUND(COALESCE(k.pi_scope,0),2) as ach"),
                'k.stability_index',
                'k.risk_index',
                'k.improvement_index',
                'k.status_label',
                'k.meta',
                DB::raw("u.id as ref_user_id"),
            ])
            ->orderByDesc('k.leadership_index')
            ->limit(200)
            ->get();

        return $rows->map(function ($r) {
            $meta = $this->safeJson($r->meta ?? null);

            return [
                'level' => $r->level,
                'role'  => $r->role,
                'name'  => $r->name,
                'unit'  => null,
                'scope_count' => (int)($r->scope_count ?? 0),
                'period'=> $r->period,
                'mode'  => strtoupper((string)($r->mode ?? 'EOM')),
                'score' => (float)($r->score ?? 0),
                'ach'   => (float)($r->ach ?? 0),
                'rank'  => null,
                'risk_badge' => $r->status_label ?? null,
                'delta_pp'   => null,
                'detail' => [
                    'components' => [
                        'stability' => (float)($r->stability_index ?? 0),
                        'risk'      => (float)($r->risk_index ?? 0),
                        'improvement' => (float)($r->improvement_index ?? 0),
                    ],
                    'audit' => $meta,
                ],
                'ref' => [
                    'user_id' => (int)($r->ref_user_id ?? 0),
                ],
            ];
        })->values()->all();
    }

    // ============================
    // KSLR (table: kpi_kslr_monthlies)
    // ============================
    private function buildKslr(string $periodYmd, string $periodYm, string $q): array
    {
        $qb = DB::table('kpi_kslr_monthlies as k')
            ->join('users as u', 'u.id', '=', 'k.kslr_id')
            ->whereDate('k.period', $periodYmd)
            ->whereRaw("UPPER(COALESCE(u.level,'')) = 'KSLR'");

        if ($q !== '') {
            $qb->where('u.name', 'like', "%$q%");
        }

        // scope TLRO under KSLR
        $scopeSql = "(
            SELECT COUNT(DISTINCT oa.user_id)
            FROM org_assignments oa
            JOIN users su ON su.id = oa.user_id
            WHERE oa.leader_id = u.id
              AND oa.is_active = 1
              AND oa.effective_from <= '{$periodYmd}'
              AND (oa.effective_to IS NULL OR oa.effective_to >= '{$periodYmd}')
              AND UPPER(COALESCE(su.level,'')) IN ('TLRO','TLSO','TLUM','TLFE','TLBE')
        )";

        $rows = $qb->select([
                DB::raw("'KASI' as level"),
                DB::raw("'KSLR' as role"),
                'u.name as name',
                DB::raw("NULL as unit"),
                DB::raw($scopeSql . " as scope_count"),
                DB::raw("'" . $periodYm . "' as period"),
                DB::raw("UPPER(COALESCE(k.calc_mode,'eom')) as mode"),
                DB::raw("COALESCE(k.total_score_weighted,0) as score"),

                // ACH: avg 4 komponen, DPK migrasi dibalik (makin kecil makin bagus)
                DB::raw("ROUND(
                    (
                        COALESCE(k.kyd_ach_pct,0)
                      + COALESCE(k.rr_pct,0)
                      + COALESCE(k.community_pct,0)
                      + (100-COALESCE(k.dpk_mig_pct,0))
                    ) / 4
                , 2) as ach"),

                'k.kyd_ach_pct',
                'k.dpk_mig_pct',
                'k.rr_pct',
                'k.community_pct',
                'k.score_kyd',
                'k.score_dpk',
                'k.score_rr',
                'k.score_com',
                'k.meta as meta_json',
                DB::raw("u.id as ref_user_id"),
            ])
            ->orderByDesc('k.total_score_weighted')
            ->limit(100)
            ->get();

        return $rows->map(function($r){
            $meta = $this->safeJson($r->meta_json ?? null);

            $components = [
                'kyd' => [
                    'ach_pct' => (float)($r->kyd_ach_pct ?? 0),
                    'score'   => (int)($r->score_kyd ?? 0),
                ],
                'dpk_mig' => [
                    'pct'   => (float)($r->dpk_mig_pct ?? 0),
                    'score' => (int)($r->score_dpk ?? 0),
                ],
                'rr' => [
                    'pct'   => (float)($r->rr_pct ?? 0),
                    'score' => (int)($r->score_rr ?? 0),
                ],
                'community' => [
                    'pct'   => (float)($r->community_pct ?? 0),
                    'score' => (int)($r->score_com ?? 0),
                ],
            ];

            return [
                'level' => $r->level,
                'role'  => $r->role,
                'name'  => $r->name,
                'unit'  => null,
                'scope_count' => (int)($r->scope_count ?? 0),
                'period'=> $r->period,
                'mode'  => strtoupper((string)($r->mode ?? 'EOM')),
                'score' => (float)($r->score ?? 0),
                'ach'   => (float)($r->ach ?? 0),
                'rank'  => null,
                'risk_badge' => $meta['risk_badge'] ?? null,
                'delta_pp'   => $meta['delta']['pp'] ?? null,
                'detail' => [
                    'components' => $components,
                    'audit'      => $meta,
                ],
                'ref' => [
                    'user_id' => (int)($r->ref_user_id ?? 0),
                ],
            ];
        })->values()->all();
    }

    // ============================
    // KSBE (table: kpi_ksbe_monthlies)
    // ============================
    private function buildKsbe(string $periodYmd, string $periodYm, string $q): array
    {
        $qb = DB::table('kpi_ksbe_monthlies as k')
            ->join('users as u', 'u.id', '=', 'k.ksbe_user_id')
            ->whereDate('k.period', $periodYmd);

        // ❌ Jangan hard filter u.level = KSBE (sering tidak match di tabel users)
        // ->whereRaw("UPPER(COALESCE(u.level,'')) = 'KSBE'");

        if ($q !== '') {
            $qb->where('u.name', 'like', "%$q%");
        }

        // mode: pakai calc_mode kalau kolom ada, kalau tidak fallback EOM
        $modeExpr = $this->hasColumn('kpi_ksbe_monthlies', 'calc_mode')
            ? "UPPER(COALESCE(k.calc_mode,'eom'))"
            : "'EOM'";

        $rows = $qb->select([
                DB::raw("'KASI' as level"),
                DB::raw("'KSBE' as role"),
                'u.name as name',
                DB::raw("NULL as unit"),

                DB::raw("COALESCE(k.scope_be_count,0) as scope_count"),
                DB::raw("'" . $periodYm . "' as period"),
                DB::raw($modeExpr . " as mode"),

                // score & ach ringkas
                DB::raw("COALESCE(k.li_total,0) as score"),
                DB::raw("COALESCE(k.pi_scope_total,0) as ach"),

                // komponen penting
                'k.scope_be_count',
                'k.active_be_count',
                'k.coverage_pct',

                'k.target_os_selesai',
                'k.target_noa_selesai',
                'k.target_bunga_masuk',
                'k.target_denda_masuk',

                'k.actual_os_selesai',
                'k.actual_noa_selesai',
                'k.actual_bunga_masuk',
                'k.actual_denda_masuk',

                'k.ach_os',
                'k.ach_noa',
                'k.ach_bunga',
                'k.ach_denda',

                'k.score_os',
                'k.score_noa',
                'k.score_bunga',
                'k.score_denda',

                'k.pi_os',
                'k.pi_noa',
                'k.pi_bunga',
                'k.pi_denda',
                'k.pi_scope_total',
                'k.pi_stddev',

                'k.bottom_be_count',
                'k.bottom_pct',
                'k.si_coverage_score',
                'k.si_spread_score',
                'k.si_bottom_score',
                'k.si_total',

                'k.ri_score',
                'k.ii_score',
                'k.li_total',

                'k.json_insights',
                'k.calculated_at',

                DB::raw("u.id as ref_user_id"),
            ])
            ->orderByDesc('k.li_total')
            ->limit(100)
            ->get();

        return $rows->map(function ($r) {

            $insights = $this->safeJson($r->json_insights ?? null);

            $components = [
                'scope' => [
                    'scope_be_count'  => (int)($r->scope_be_count ?? 0),
                    'active_be_count' => (int)($r->active_be_count ?? 0),
                    'coverage_pct'    => (float)($r->coverage_pct ?? 0),
                ],
                'targets' => [
                    'os_selesai'   => (float)($r->target_os_selesai ?? 0),
                    'noa_selesai'  => (int)  ($r->target_noa_selesai ?? 0),
                    'bunga_masuk'  => (float)($r->target_bunga_masuk ?? 0),
                    'denda_masuk'  => (float)($r->target_denda_masuk ?? 0),
                ],
                'actuals' => [
                    'os_selesai'   => (float)($r->actual_os_selesai ?? 0),
                    'noa_selesai'  => (int)  ($r->actual_noa_selesai ?? 0),
                    'bunga_masuk'  => (float)($r->actual_bunga_masuk ?? 0),
                    'denda_masuk'  => (float)($r->actual_denda_masuk ?? 0),
                ],
                'achievement' => [
                    'ach_os'    => (float)($r->ach_os ?? 0),
                    'ach_noa'   => (float)($r->ach_noa ?? 0),
                    'ach_bunga' => (float)($r->ach_bunga ?? 0),
                    'ach_denda' => (float)($r->ach_denda ?? 0),
                ],
                'scores' => [
                    'score_os'    => (int)($r->score_os ?? 0),
                    'score_noa'   => (int)($r->score_noa ?? 0),
                    'score_bunga' => (int)($r->score_bunga ?? 0),
                    'score_denda' => (int)($r->score_denda ?? 0),
                ],
                'pi' => [
                    'pi_os'    => (float)($r->pi_os ?? 0),
                    'pi_noa'   => (float)($r->pi_noa ?? 0),
                    'pi_bunga' => (float)($r->pi_bunga ?? 0),
                    'pi_denda' => (float)($r->pi_denda ?? 0),
                    'pi_scope_total' => (float)($r->pi_scope_total ?? 0),
                    'pi_stddev' => (float)($r->pi_stddev ?? 0),
                ],
                'si' => [
                    'bottom_be_count' => (int)($r->bottom_be_count ?? 0),
                    'bottom_pct'      => (float)($r->bottom_pct ?? 0),
                    'coverage_score'  => (int)($r->si_coverage_score ?? 0),
                    'spread_score'    => (int)($r->si_spread_score ?? 0),
                    'bottom_score'    => (int)($r->si_bottom_score ?? 0),
                    'si_total'        => (float)($r->si_total ?? 0),
                ],
                'ri' => (int)($r->ri_score ?? 0),
                'ii' => (int)($r->ii_score ?? 0),
                'li' => (float)($r->li_total ?? 0),
            ];

            $riskBadge = $insights['risk_badge'] ?? null;

            $audit = [
                'calculated_at' => $r->calculated_at ?? null,
                'json_insights' => $insights,
            ];

            return [
                'level' => $r->level,
                'role'  => $r->role,
                'name'  => $r->name,
                'unit'  => null,
                'scope_count' => (int)($r->scope_count ?? 0),
                'period'=> $r->period,
                'mode'  => strtoupper((string)($r->mode ?? 'EOM')),
                'score' => (float)($r->score ?? 0),
                'ach'   => (float)($r->ach ?? 0),
                'rank'  => null,
                'risk_badge' => $riskBadge,
                'delta_pp'   => $insights['delta']['pp'] ?? null,
                'detail' => [
                    'components' => $components,
                    'audit'      => $audit,
                ],
                'ref' => [
                    'user_id' => (int)($r->ref_user_id ?? 0),
                ],
            ];
        })->values()->all();
    }

    // ============================
    // KSFE (table: kpi_ksfe_monthlies)
    // ============================
    private function buildKsfe(string $periodYmd, string $periodYm, string $q): array
    {
        $qb = DB::table('kpi_ksfe_monthlies as k')
            ->join('users as u', 'u.id', '=', 'k.ksfe_id')
            ->whereDate('k.period', $periodYmd);

        // ❌ Jangan hard filter u.level = KSFE (sering tidak match di users)
        // ->whereRaw("UPPER(COALESCE(u.level,'')) = 'KSFE'");

        if ($q !== '') {
            $qb->where('u.name', 'like', "%$q%");
        }

        $rows = $qb->select([
                DB::raw("'KASI' as level"),
                DB::raw("'KSFE' as role"),
                'u.name as name',
                DB::raw("NULL as unit"),

                // scope_count = jumlah TLFE yang di-scope
                DB::raw("COALESCE(k.tlfe_count,0) as scope_count"),

                DB::raw("'" . $periodYm . "' as period"),
                DB::raw("UPPER(COALESCE(k.calc_mode,'eom')) as mode"),

                // score utama
                DB::raw("COALESCE(k.leadership_index,0) as score"),

                // ach ringkas = pi_scope
                DB::raw("COALESCE(k.pi_scope,0) as ach"),

                // komponen
                'k.tlfe_count',
                'k.pi_scope',
                'k.stability_index',
                'k.risk_index',
                'k.improvement_index',
                'k.leadership_index',
                'k.status_label',
                'k.meta',

                DB::raw("u.id as ref_user_id"),
            ])
            ->orderByDesc('k.leadership_index')
            ->limit(100)
            ->get();

        return $rows->map(function ($r) {

            $meta = $this->safeJson($r->meta ?? null);

            $components = [
                'pi_scope' => (float)($r->pi_scope ?? 0),
                'stability_index' => (float)($r->stability_index ?? 0),
                'risk_index' => (float)($r->risk_index ?? 0),
                'improvement_index' => (float)($r->improvement_index ?? 0),
                'leadership_index' => (float)($r->leadership_index ?? 0),
            ];

            // risk badge: kalau ada status_label pakai itu,
            // kalau meta punya risk_badge, pakai meta (lebih kaya)
            $riskBadge = $meta['risk_badge'] ?? ($r->status_label ?? null);

            $audit = [
                'status_label'  => $r->status_label ?? null,
                'meta'          => $meta,
            ];

            return [
                'level' => $r->level,
                'role'  => $r->role,
                'name'  => $r->name,
                'unit'  => null,
                'scope_count' => (int)($r->scope_count ?? 0),
                'period'=> $r->period,
                'mode'  => strtoupper((string)($r->mode ?? 'EOM')),
                'score' => (float)($r->score ?? 0),
                'ach'   => (float)($r->ach ?? 0),
                'rank'  => null,
                'risk_badge' => $riskBadge,
                'delta_pp'   => $meta['delta']['pp'] ?? null,
                'detail' => [
                    'components' => $components,
                    'audit'      => $audit,
                ],
                'ref' => [
                    'user_id' => (int)($r->ref_user_id ?? 0),
                ],
            ];
        })->values()->all();
    }

    // ============================
    // KBL (table: kpi_kbl_monthlies)
    // ============================
    private function buildKbl(string $periodYmd, string $periodYm, string $q): array
    {
        $qb = DB::table('kpi_kbl_monthlies as k')
            ->join('users as u', 'u.id', '=', 'k.kbl_id')
            ->whereDate('k.period', $periodYmd);

        if ($q !== '') {
            $qb->where('u.name', 'like', "%$q%");
        }

        // ✅ FIX: jangan define scope_count dua kali
        $scopeSql = "(
            SELECT COUNT(DISTINCT oa.user_id)
            FROM org_assignments oa
            JOIN users su ON su.id = oa.user_id
            WHERE oa.leader_id = u.id
              AND oa.is_active = 1
              AND oa.effective_from <= '{$periodYmd}'
              AND (oa.effective_to IS NULL OR oa.effective_to >= '{$periodYmd}')
              AND UPPER(COALESCE(su.level,'')) IN ('TLRO','TLUM','TLFE','TLBE')
        )";

        $rows = $qb->select([
                DB::raw("'KABAG' as level"),
                DB::raw("'KBL' as role"),
                'u.name as name',
                DB::raw("NULL as unit"),
                DB::raw($scopeSql . " as scope_count"),
                DB::raw("'" . $periodYm . "' as period"),
                DB::raw("UPPER(k.calc_mode) as mode"),
                DB::raw("COALESCE(k.total_score_weighted,0) as score"),
                DB::raw("COALESCE(k.kyd_ach_pct,0) as ach"),
                'k.meta as meta_json',
                DB::raw("u.id as ref_user_id"),
            ])
            ->orderByDesc('k.total_score_weighted')
            ->limit(50)
            ->get();

        return $rows->map(function($r){
            $meta = $this->safeJson($r->meta_json ?? null);

            return [
                'level' => $r->level,
                'role'  => $r->role,
                'name'  => $r->name,
                'unit'  => null,
                'scope_count' => (int)($r->scope_count ?? 0),
                'period'=> $r->period,
                'mode'  => strtoupper((string)$r->mode),
                'score' => (float)$r->score,
                'ach'   => (float)$r->ach,
                'rank'  => null,
                'risk_badge' => $meta['risk_badge'] ?? null,
                'delta_pp'   => $meta['delta']['pp'] ?? null,
                'detail' => [
                    'components' => [
                        'os_actual'       => (float)($meta['os_actual'] ?? 0),
                        'os_target'       => (float)($meta['os_target'] ?? 0),
                        'dpk_mig_pct'     => (float)($meta['dpk_mig_pct'] ?? 0),
                        'npl_ratio_pct'   => (float)($meta['npl_ratio_pct'] ?? 0),
                        'interest_ach_pct'=> (float)($meta['interest_ach_pct'] ?? 0),
                    ],
                    'audit' => $meta,
                ],
                'ref' => [
                    'user_id' => (int)$r->ref_user_id,
                ],
            ];
        })->values()->all();
    }

    // ============================
    // Helpers
    // ============================
    private function safeJson($val): array
    {
        if (is_array($val)) return $val;
        if (!is_string($val) || trim($val) === '') return [];
        $j = json_decode($val, true);
        return is_array($j) ? $j : [];
    }

    private function hasColumn(string $table, string $col): bool
    {
        static $cache = [];
        $key = $table.'|'.$col;
        if (array_key_exists($key, $cache)) return $cache[$key];

        $db = DB::getDatabaseName();
        $exists = DB::table('information_schema.columns')
            ->where('table_schema', $db)
            ->where('table_name', $table)
            ->where('column_name', $col)
            ->exists();

        return $cache[$key] = $exists;
    }

    private function dedupeRows(array $rows): array
{
    $seen = [];
    $out  = [];

    foreach ($rows as $r) {
        // pastikan ada struktur minimal
        $level = strtoupper((string)($r['level'] ?? ''));
        $role  = strtoupper((string)($r['role'] ?? ''));

        $refUserId = (int)($r['ref']['user_id'] ?? 0);
        $aoCode    = (string)($r['ref']['ao_code'] ?? '');
        $period    = (string)($r['period'] ?? '');
        $mode      = strtoupper((string)($r['mode'] ?? ''));

        // ✅ KEY harus include level + role
        // plus ref_user_id/ao_code supaya unique per orang
        // plus period+mode supaya gak tabrakan lintas bulan/mode
        $key = implode('|', [
            $period,
            $mode,
            $level,
            $role,
            $refUserId > 0 ? $refUserId : $aoCode,
        ]);

        if (isset($seen[$key])) continue;

        $seen[$key] = true;
        $out[] = $r;
    }

    return $out;
}
}