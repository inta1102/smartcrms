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

    /**
     * Build universal KPI summary rows.
     * Start: RO (staff) + TLRO (TL) + KBL (Kasi/Kabag)
     * Next: SO/FE/BE/AO + KSFE/KSBE/TLFE/TLBE/TLUM
     */
    public function build(string $rawPeriod, array $filters = []): array
    {
        $periodYmd = $this->normalizePeriodDate($rawPeriod);
        $periodYm  = Carbon::parse($periodYmd)->format('Y-m');

        $level = strtoupper($filters['level'] ?? 'ALL');
        $role  = strtoupper($filters['role']  ?? 'ALL');
        $q     = trim((string)($filters['q'] ?? ''));

        $rows = [];

        // STAFF
        if ($level === 'ALL' || $level === 'STAFF') {

            if ($role === 'ALL' || $role === 'RO') {
                $rows = array_merge($rows, $this->buildStaffRo($periodYmd, $periodYm, $q));
            }
            if ($role === 'ALL' || $role === 'FE') {
                $rows = array_merge($rows, $this->buildStaffFe($periodYmd, $periodYm, $q));
            }
            if ($role === 'ALL' || $role === 'BE') {
                $rows = array_merge($rows, $this->buildStaffBe($periodYmd, $periodYm, $q));
            }
            if ($role === 'ALL' || $role === 'AO') {
                $rows = array_merge($rows, $this->buildStaffAo($periodYmd, $periodYm, $q));
            }
            if ($role === 'ALL' || $role === 'SO') {
                $rows = array_merge($rows, $this->buildStaffSo($periodYmd, $periodYm, $q));
            }
        }

        // TL
        if ($level === 'ALL' || $level === 'TL') {
            if ($role === 'ALL' || $role === 'TLRO') {
                $rows = array_merge($rows, $this->buildTlro($periodYmd, $periodYm, $q));
            }

            if ($role === 'ALL' || $role === 'TLUM') {
                $rows = array_merge($rows, $this->buildTlum($periodYmd, $periodYm, $q));
            }

            if ($role === 'ALL' || $role === 'TLFE') {
                $rows = array_merge($rows, $this->buildTlfe($periodYmd, $periodYm, $q));
            }

            // if ($role === 'ALL' || $role === 'TLBE') {
            //     $rows = array_merge($rows, $this->buildTlbe($periodYmd, $periodYm, $q));
            // }

        }

       // KABAG (KBL)
        if ($level === 'ALL' || $level === 'KABAG') {
            if ($role === 'ALL' || $role === 'KBL') {
                $rows = array_merge($rows, $this->buildKbl($periodYmd, $periodYm, $q));
            }
        }

        // sort: KASI -> TL -> STAFF, lalu score desc
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
            ->join('users as u', 'u.ao_code', '=', 'k.ao_code') // ✅ RO di table ini key-nya ao_code
            ->where('u.level', '=', 'RO')
            ->whereDate('k.period_month', $periodYmd);

        if ($q !== '') {
            $qb->where(function($w) use ($q){
                $w->where('u.name', 'like', "%$q%")
                ->orWhere('k.ao_code', 'like', "%$q%");
            });
        }

        $rows = $qb->select([
                DB::raw("'STAFF' as level"),
                DB::raw("'RO' as role"),
                'u.name as name',
                DB::raw("NULL as unit"),
                DB::raw("NULL as scope_count"),
                DB::raw("'" . $periodYm . "' as period"),

                // ✅ mode asli dari tabel
                DB::raw("UPPER(COALESCE(k.calc_mode,'realtime')) as mode"),

                // ✅ score total sesuai tabel
                DB::raw("COALESCE(k.total_score_weighted,0) as score"),

                // ✅ ach overall: kita bikin simple avg 4 komponen (optional)
                DB::raw("ROUND( (COALESCE(k.topup_pct,0)+COALESCE(k.repayment_pct,0)+COALESCE(k.noa_pct,0)+(100-COALESCE(k.dpk_pct,0))) / 4 , 2) as ach"),

                // ✅ meta kolom-kolom penting (kita kirim sebagai json string gabungan)
                DB::raw("JSON_OBJECT(
                    'baseline_ok', COALESCE(k.baseline_ok,0),
                    'baseline_note', k.baseline_note,
                    'start_snapshot_month', k.start_snapshot_month,
                    'end_snapshot_month', k.end_snapshot_month,
                    'calc_source_position_date', k.calc_source_position_date,
                    'locked_at', k.locked_at
                ) as meta_json"),

                // components utama RO
                'k.ao_code',
                'k.topup_realisasi',
                'k.topup_target',
                'k.topup_pct',
                'k.topup_score',
                'k.topup_cif_count',
                'k.topup_cif_new_count',
                'k.topup_max_cif_amount',
                'k.topup_concentration_pct',
                'k.topup_top3_json',
                'k.topup_adj_json',

                'k.repayment_rate',
                'k.repayment_pct',
                'k.repayment_score',
                'k.repayment_total_os',
                'k.repayment_os_lancar',

                'k.noa_realisasi',
                'k.noa_target',
                'k.noa_pct',
                'k.noa_score',

                'k.dpk_pct',
                'k.dpk_score',
                'k.dpk_migrasi_count',
                'k.dpk_migrasi_os',
                'k.dpk_total_os_akhir',

                // ref
                DB::raw("u.id as ref_user_id"),
            ])
            ->orderByDesc('k.total_score_weighted')
            ->limit(500)
            ->get();

        return $rows->map(function($r){
            $meta = $this->safeJson($r->meta_json ?? null);

            $top3 = $this->safeJson($r->topup_top3_json ?? null);
            $adj  = $this->safeJson($r->topup_adj_json ?? null);

            // ✅ components siap dipakai untuk tabel ringkasan / audit card
            $components = [
                'topup' => [
                    'realisasi' => (float)($r->topup_realisasi ?? 0),
                    'target'    => (float)($r->topup_target ?? 0),
                    'pct'       => (float)($r->topup_pct ?? 0),
                    'score'     => (int)  ($r->topup_score ?? 0),
                    'cif_count' => (int)  ($r->topup_cif_count ?? 0),
                    'cif_new_count' => (int)($r->topup_cif_new_count ?? 0),
                    'max_cif_amount' => (float)($r->topup_max_cif_amount ?? 0),
                    'concentration_pct' => (float)($r->topup_concentration_pct ?? 0),
                    'top3' => $top3,
                    'adj'  => $adj,
                ],
                'repayment' => [
                    'rate'      => (float)($r->repayment_rate ?? 0),
                    'pct'       => (float)($r->repayment_pct ?? 0),
                    'score'     => (int)  ($r->repayment_score ?? 0),
                    'total_os'  => (float)($r->repayment_total_os ?? 0),
                    'os_lancar' => (float)($r->repayment_os_lancar ?? 0),
                ],
                'noa' => [
                    'realisasi' => (int)  ($r->noa_realisasi ?? 0),
                    'target'    => (int)  ($r->noa_target ?? 0),
                    'pct'       => (float)($r->noa_pct ?? 0),
                    'score'     => (int)  ($r->noa_score ?? 0),
                ],
                'dpk' => [
                    'pct'          => (float)($r->dpk_pct ?? 0),
                    'score'        => (int)  ($r->dpk_score ?? 0),
                    'migrasi_count' => (int)  ($r->dpk_migrasi_count ?? 0),
                    'migrasi_os'    => (float)($r->dpk_migrasi_os ?? 0),
                    'total_os_akhir'=> (float)($r->dpk_total_os_akhir ?? 0),
                ],
            ];

            // ✅ audit ringkas (buat “Audit & Source” tabel ringkasan)
            $audit = [
                'baseline_ok' => (int)($meta['baseline_ok'] ?? 0),
                'baseline_note' => $meta['baseline_note'] ?? null,
                'start_snapshot_month' => $meta['start_snapshot_month'] ?? null,
                'end_snapshot_month' => $meta['end_snapshot_month'] ?? null,
                'calc_source_position_date' => $meta['calc_source_position_date'] ?? null,
                'locked_at' => $meta['locked_at'] ?? null,
            ];

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
        ->where('u.level', '=', 'FE')
        ->whereDate('k.period', $periodYmd);

    if ($q !== '') {
        $qb->where(function($w) use ($q){
            $w->where('u.name', 'like', "%$q%")
              ->orWhere('k.ao_code', 'like', "%$q%");
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
            'k.ao_code',
            DB::raw("u.id as ref_user_id"),
        ])
        ->orderByDesc('k.total_score_weighted')
        ->limit(500)
        ->get();

    return $rows->map(function($r){
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
        ->where('u.level', '=', 'BE')
        ->whereDate('k.period', $periodYmd);

    if ($q !== '') {
        $qb->where('u.name', 'like', "%$q%");
    }

    $rows = $qb->select([
            DB::raw("'STAFF' as level"),
            DB::raw("'BE' as role"),
            'u.name as name',
            DB::raw("NULL as unit"),
            DB::raw("NULL as scope_count"),
            DB::raw("'" . $periodYm . "' as period"),
            DB::raw("'EOM' as mode"), // tabel BE belum ada calc_mode, jadi set default
            DB::raw("COALESCE(k.final_score,0) as score"),
            DB::raw("COALESCE(k.recovery_pct,0) as ach"),
            DB::raw("JSON_OBJECT(
                'status', k.status,
                'approval_note', k.approval_note,
                'submitted_at', k.submitted_at,
                'approved_at', k.approved_at,
                'approved_by', k.approved_by
            ) as meta_json"),
            DB::raw("u.id as ref_user_id"),
        ])
        ->orderByDesc('k.final_score')
        ->limit(500)
        ->get();

    return $rows->map(function($r){
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
                    'recovery_principal' => (float)($r->recovery_principal ?? 0),
                    'actual_os_selesai'  => (float)($r->actual_os_selesai ?? 0),
                    'actual_noa_selesai' => (int)  ($r->actual_noa_selesai ?? 0),
                    'actual_bunga_masuk' => (float)($r->actual_bunga_masuk ?? 0),
                ],
                'audit' => $meta,
            ],
            'ref' => [
                'user_id' => (int)($r->ref_user_id ?? 0),
            ],
        ];
    })->values()->all();
}

private function buildStaffAo(string $periodYmd, string $periodYm, string $q): array
{
    $qb = DB::table('kpi_ao_monthlies as k')
        ->join('users as u', 'u.id', '=', 'k.user_id')
        ->where('u.level', '=', 'AO')
        ->whereDate('k.period', $periodYmd);

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
            DB::raw("UPPER(COALESCE(k.data_source,'live')) as mode"), // live/final
            DB::raw("COALESCE(k.score_total,0) as score"),
            DB::raw("COALESCE(k.rr_pct,0) as ach"),
            DB::raw("JSON_OBJECT(
                'is_final', COALESCE(k.is_final,0),
                'calculated_at', k.calculated_at,
                'scheme', k.scheme
            ) as meta_json"),
            'k.ao_code',
            DB::raw("u.id as ref_user_id"),
        ])
        ->orderByDesc('k.score_total')
        ->limit(500)
        ->get();

    return $rows->map(function($r){
        $meta = $this->safeJson($r->meta_json ?? null);
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
                'components' => [
                    'os_growth' => (float)($r->os_growth ?? 0),
                    'noa_growth'=> (int)($r->noa_growth ?? 0),
                    'rr_pct'    => (float)($r->rr_pct ?? 0),
                    'npl_migration_pct' => (float)($r->npl_migration_pct ?? 0),
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

private function buildStaffSo(string $periodYmd, string $periodYm, string $q): array
{
    $qb = DB::table('kpi_so_monthlies as k')
        ->join('users as u', 'u.id', '=', 'k.user_id')
        ->where('u.level', '=', 'SO')
        ->whereDate('k.period', $periodYmd);

    if ($q !== '') {
        $qb->where(function($w) use ($q){
            $w->where('u.name', 'like', "%$q%")
              ->orWhere('k.ao_code', 'like', "%$q%");
        });
    }

    $rows = $qb->select([
            DB::raw("'STAFF' as level"),
            DB::raw("'SO' as role"),
            'u.name as name',
            DB::raw("NULL as unit"),
            DB::raw("NULL as scope_count"),
            DB::raw("'" . $periodYm . "' as period"),
            DB::raw("'LIVE' as mode"),
            DB::raw("COALESCE(k.score_total,0) as score"),
            DB::raw("COALESCE(k.rr_pct,0) as ach"),
            DB::raw("JSON_OBJECT(
                'is_final', COALESCE(k.is_final,0),
                'calculated_at', k.calculated_at
            ) as meta_json"),
            'k.ao_code',
            DB::raw("u.id as ref_user_id"),
        ])
        ->orderByDesc('k.score_total')
        ->limit(500)
        ->get();

    return $rows->map(function($r){
        $meta = $this->safeJson($r->meta_json ?? null);
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
                'components' => [
                    'os_disbursement' => (float)($r->os_disbursement ?? 0),
                    'os_adjustment'   => (float)($r->os_adjustment ?? 0),
                    'rr_pct'          => (float)($r->rr_pct ?? 0),
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
            // ✅ penting: hanya TLUM yg tampil, meskipun tabel kemasukan KBL/KSLR
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

                // ✅ TLUM table tidak punya calc_mode -> kita set aja konstanta
                DB::raw("'REALTIME' as mode"),

                // ✅ score utama TLUM
                DB::raw("COALESCE(k.pi_total,0) as score"),

                // ✅ rr_pct tidak ada -> hitung dari rr_actual / rr_target
                DB::raw("
                    CASE
                        WHEN COALESCE(k.rr_target,0) > 0
                            THEN ROUND((COALESCE(k.rr_actual,0) / k.rr_target) * 100, 2)
                        ELSE 0
                    END as rr_pct_calc
                "),

                // ✅ ACH% kita ambil avg 5 komponen:
                // noa_pct, os_pct, rr_pct_calc, com_pct, day_pct
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

                // targets
                'k.noa_target',
                'k.os_target',
                'k.rr_target',
                'k.com_target',
                'k.day_target',

                // actuals
                'k.noa_actual',
                'k.os_actual',
                'k.rr_actual',
                'k.com_actual',
                'k.day_actual',

                // pct
                'k.noa_pct',
                'k.os_pct',
                'k.com_pct',
                'k.day_pct',

                // score (note: tidak ada score_day)
                'k.score_noa',
                'k.score_os',
                'k.score_rr',
                'k.score_com',

                DB::raw("u.id as ref_user_id"),
            ])
            ->orderByDesc('k.pi_total')
            ->limit(200)
            ->get();

        // ✅ mapping ke format universal summary kamu
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
                    'pct'    => $rrPctCalc, // ✅ hasil hitung
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
                    // ❌ tidak ada score_day di tabel -> sengaja tidak dibuat
                ],
            ];

            return [
                'level' => $r->level,
                'role'  => $r->role,
                'name'  => $r->name,
                'unit'  => $r->unit,
                'scope_count' => null,
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

        // ✅ anti duplikat: jaga-jaga kalau di layer lain ada merge dobel
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
                'mode'  => strtolower($r->mode ?? 'eom'),
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
    // KBL (table: kpi_kbl_monthlies)
    // (sementara untuk level KASI/KABAG)
    // ============================
    private function buildKbl(string $periodYmd, string $periodYm, string $q): array
    {
        $qb = DB::table('kpi_kbl_monthlies as k')
            ->join('users as u', 'u.id', '=', 'k.kbl_id')
            ->whereDate('k.period', $periodYmd);

        if ($q !== '') {
            $qb->where('u.name', 'like', "%$q%");
        }

        $rows = $qb->select([
                DB::raw("'KABAG' as level"), // sebelumnya 'KABAG'
                DB::raw("'KBL' as role"),
                'u.name as name',
                DB::raw("NULL as unit"),
                DB::raw("(
                    SELECT COUNT(DISTINCT oa.user_id)
                    FROM org_assignments oa
                    JOIN users su ON su.id = oa.user_id
                    WHERE oa.leader_id = u.id
                    AND oa.is_active = 1
                    AND oa.effective_from <= '{$periodYmd}'
                    AND (oa.effective_to IS NULL OR oa.effective_to >= '{$periodYmd}')
                    AND UPPER(COALESCE(su.level,'')) IN ('TLRO','TLUM','TLFE','TLBE')
                ) as scope_count"),
                DB::raw("'" . $periodYm . "' as period"),
                DB::raw("UPPER(k.calc_mode) as mode"),
                DB::raw("COALESCE(k.total_score_weighted,0) as score"),
                DB::raw("COALESCE(k.kyd_ach_pct,0) as ach"),
                'k.meta as meta_json',
                DB::raw("u.id as ref_user_id"),
                // di select buildKbl()
                DB::raw("(
                    SELECT COUNT(DISTINCT oa.user_id)
                    FROM org_assignments oa
                    JOIN users su ON su.id = oa.user_id
                    WHERE oa.leader_id = k.kbl_id
                    AND (oa.is_active = 1)
                    AND UPPER(COALESCE(su.level,'')) IN ('TLRO','TLUM')
                ) as scope_count"),
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
                'scope_count' => null,
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
        $out = [];
        foreach ($rows as $r) {
            $uid = $r['ref']['user_id'] ?? null;
            $key = ($r['level'] ?? '-') . '|'
                . ($r['role'] ?? '-')  . '|'
                . ($r['period'] ?? '-') . '|'
                . ($uid ?? '-');

            // kalau dobel, keep yang score lebih tinggi (atau keep first)
            if (!isset($out[$key])) {
                $out[$key] = $r;
            } else {
                $prev = (float)($out[$key]['score'] ?? 0);
                $cur  = (float)($r['score'] ?? 0);
                if ($cur > $prev) $out[$key] = $r;
            }
        }
        return array_values($out);
    }
}