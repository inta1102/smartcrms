<?php

namespace App\Services\Kpi;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class KblMonthlyBuilder
{
    /**
     * Build KPI Kabag Lending (KBL) per period.
     *
     * RULES:
     * - KYD: OS actual (outstanding) vs target_os
     * - Migrasi DPK: prev EOM kolek=2 -> current kolek>=3 (numerator pakai prev.outstanding)
     * - NPL: ratio (kolek>=3) + achievement vs target_npl_pct
     * - Bunga: sum loan_installments.interest_paid vs target_interest_income
     * - Komunitas: placeholder (bisa diisi dari sumber lain)
     *
     * DATA SOURCE:
     * - realtime: loan_accounts (current) + prev snapshot for cohort baseline
     * - eom: snapshots prev & cur
     */
    public function build(int $kblId, string $periodYmd, array $scopeAoCodes, ?string $reqMode = null): array
    {
        $period     = Carbon::parse($periodYmd)->startOfMonth();
        $periodDate = $period->toDateString(); // YYYY-MM-01
        $prevMonth  = $period->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();

        // ==========================================================
        // MODE (AUTO ONLY)
        // - bulan ini  : realtime
        // - bulan lalu+: eom
        // ==========================================================
        $nowMonth = now()->startOfMonth();
        $mode = $period->equalTo($nowMonth) ? 'realtime' : 'eom';

        // (opsional) kalau kamu MASIH mau allow override, buka komentar ini:
        // if (is_string($reqMode)) {
        //     $m = strtolower(trim($reqMode));
        //     if (in_array($m, ['realtime', 'eom'], true)) $mode = $m;
        // }

        // ==========================================================
        // SCOPE (optional) - kosong = GLOBAL
        // ==========================================================
        $aoCodes = collect($scopeAoCodes)
            ->map(fn($x) => str_pad(trim((string)$x), 6, '0', STR_PAD_LEFT))
            ->filter(fn($x) => $x !== '' && $x !== '000000')
            ->unique()
            ->values()
            ->all();

        $isGlobal = empty($aoCodes);

        // ==========================================================
        // LOAD TARGET
        // ==========================================================
        $target = DB::table('kpi_kbl_targets')
            ->where('kbl_id', $kblId)
            ->whereDate('period', $periodDate)
            ->first();

        $targetOs       = (float)($target->target_os ?? 0);
        $targetNplPct   = (float)($target->target_npl_pct ?? 0);
        $targetInterest = (float)($target->target_interest_income ?? 0);
        $targetCom      = (int)($target->target_community ?? 0);

        // ==========================================================
        // REALTIME "AS OF" (latest position_date) untuk transparansi
        // ==========================================================
        $latestPositionDate = null;
        if ($mode === 'realtime') {
            $latestPositionDate = DB::table('loan_accounts')->max('position_date'); // date
        }

        // ==========================================================
        // 1) OS & NPL
        // - eom: snapshots_monthly(periodDate)
        // - realtime: loan_accounts hanya latest position_date
        // ==========================================================
        [$osTotal, $osNpl] = $this->fetchOsAndNplGlobal($mode, $periodDate, $aoCodes);

        // ==========================================================
        // 2) Migrasi DPK cohort
        // base: prevMonth snapshot kolek=2
        // cur : eom = snapshot curMonth | realtime = loan_accounts latest position_date
        // ==========================================================
        [$dpkBaseOs, $dpkToNplOs, $dpkBaseNoa, $dpkToNplNoa] =
            $this->fetchDpkMigrationGlobal($mode, $prevMonth, $periodDate, $aoCodes);

        // ✅ KPI sesuai request: mig_os dibanding TOTAL OS (bukan base cohort)
        $dpkMigPct = $osTotal > 0 ? ($dpkToNplOs / $osTotal) * 100 : 0;

        // ✅ info internal (bukan KPI utama): mig_os / base_dpk_os
        $dpkCohortPct = $dpkBaseOs > 0 ? ($dpkToNplOs / $dpkBaseOs) * 100 : 0;

        // ==========================================================
        // 3) INTEREST INCOME
        // ==========================================================
        $interestActual = $this->fetchInterestIncomeGlobal($mode, $period, $aoCodes);

        // ==========================================================
        // 4) COMMUNITY ACTUAL (ambil dari tabel komunitas)
        // (anggap function ini sudah kamu buat)
        // ==========================================================
        $communityActual = $this->fetchCommunityActual($period, $aoCodes); // kalau belum ada, set 0 dulu

        // ==========================================================
        // ACHIEVEMENT
        // ==========================================================
        $kydAchPct = $targetOs > 0 ? ($osTotal / $targetOs) * 100 : 0;

        $nplRatioPct = $osTotal > 0 ? ($osNpl / $osTotal) * 100 : 0;

        // achievement NPL (lebih kecil lebih bagus)
        $nplAchPct = 0;
        if ($targetNplPct > 0) {
            if ($nplRatioPct <= $targetNplPct) $nplAchPct = 100;
            else $nplAchPct = $nplRatioPct > 0 ? ($targetNplPct / $nplRatioPct) * 100 : 0;
        }

        $interestAchPct = $targetInterest > 0 ? ($interestActual / $targetInterest) * 100 : 0;

        // community pct optional (buat display aja, scoring pakai count)
        $communityPct = $targetCom > 0 ? ($communityActual / $targetCom) * 100 : 0;

        // ==========================================================
        // SCORING (1..6)
        // ==========================================================
        $scoreKyd      = $this->scoreAchievementPct($kydAchPct, [85, 90, 95, 100, 101]);
        $scoreDpk      = $this->scoreMigrasiDpkPct($dpkMigPct);   // ✅ KPI mig_os/total_os
        $scoreNpl      = $this->scoreAchievementPct($nplAchPct, [85, 90, 95, 100, 101]);
        $scoreInterest = $this->scorePendapatanBungaPct($interestAchPct);
        $scoreCom      = $this->scoreKomunitasCount($communityActual);

        // ==========================================================
        // WEIGHTED TOTAL (PPT)
        // KYD 30%, DPK 15%, NPL 35%, Bunga 15%, Kom 5%
        // ==========================================================
        $total =
            ($scoreKyd * 0.30) +
            ($scoreDpk * 0.15) +
            ($scoreNpl * 0.35) +
            ($scoreInterest * 0.15) +
            ($scoreCom * 0.05);

        $statusLabel = $this->statusFromTotal($total);

        // ==========================================================
        // DELTA (MoM) - bandingkan dengan bulan sebelumnya (AUTO MODE)
        // ==========================================================
        $prevPeriod = $period->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $prevMode   = $prevPeriod === $nowMonth->toDateString() ? 'realtime' : 'eom'; // tapi prevPeriod pasti <= bulan lalu, jadi biasanya eom

        $prevRow = DB::table('kpi_kbl_monthlies')
            ->where('kbl_id', $kblId)
            ->whereDate('period', $prevPeriod)
            ->where('calc_mode', 'eom')   // ✅ SAFEST: prev month pakai EOM
            ->first();

        $kydPrevPct = (float)($prevRow->kyd_ach_pct ?? 0);
        $osPrev     = (float)($prevRow->os_actual ?? 0);
        $tgtPrev    = (float)($prevRow->os_target ?? 0);

        $deltaKydPp = $kydPrevPct > 0 ? ($kydAchPct - $kydPrevPct) : 0; // poin %
        $deltaOs    = $osPrev > 0 ? ($osTotal - $osPrev) : 0;

        // ==========================================================
        // UPSERT
        // ==========================================================
        $payload = [
            'kbl_id' => $kblId,
            'period' => $periodDate,
            'calc_mode' => $mode,

            'os_actual' => $osTotal,
            'os_target' => $targetOs,
            'kyd_ach_pct' => $kydAchPct,

            'dpk_base_os'    => $dpkBaseOs,
            'dpk_to_npl_os'  => $dpkToNplOs,
            'dpk_base_noa'   => $dpkBaseNoa,
            'dpk_to_npl_noa' => $dpkToNplNoa,
            'dpk_mig_pct'    => $dpkMigPct,

            'npl_os'         => $osNpl,
            'npl_ratio_pct'  => $nplRatioPct,
            'npl_target_pct' => $targetNplPct,
            'npl_ach_pct'    => $nplAchPct,

            'interest_actual' => $interestActual,
            'interest_target' => $targetInterest,
            'interest_ach_pct'=> $interestAchPct,

            'community_actual' => $communityActual,
            'community_target' => $targetCom,

            'score_kyd'      => $scoreKyd,
            'score_dpk'      => $scoreDpk,
            'score_npl'      => $scoreNpl,
            'score_interest' => $scoreInterest,
            'score_community'=> $scoreCom,

            'total_score_weighted' => round($total, 2),
            'status_label'         => $statusLabel,

            'meta' => json_encode([
                'scope' => $isGlobal ? 'GLOBAL' : 'SCOPED',
                'scope_ao_count' => count($aoCodes),
                'scope_ao_codes_sample' => array_slice($aoCodes, 0, 20),

                'prev_month' => $prevMonth,
                'cur_month'  => $periodDate,
                'mode'       => $mode,

                // realtime transparency
                'latest_position_date' => $latestPositionDate,

                // extra analytics
                'dpk_cohort_pct' => $dpkCohortPct,
                'community_pct'  => $communityPct,

                'delta' => [
                    'kyd_pp' => round($deltaKydPp, 2),     // point percentage
                    'os'     => round($deltaOs, 2),
                    'prev_period' => $prevPeriod,
                    'prev_mode'   => 'eom',
                ],

                'notes' => [
                    'dpk_kpi' => 'KPI = mig_os / total_os (target < 2%)',
                    'dpk_migration_def' => 'cohort: prev snapshot kolek=2 -> current kolek>=3; mig_os uses prev.outstanding',
                    'dpk_cohort_pct_info' => 'analytic: mig_os / base_dpk_os',
                    'realtime_guard' => 'realtime uses loan_accounts only at MAX(position_date)',
                    'npl' => 'kolek>=3',
                    'os' => 'sum outstanding',
                    'interest' => 'loan_installments.interest_paid by paid_date month',
                ],
            ], JSON_UNESCAPED_UNICODE),

            'updated_at' => now(),
        ];

        DB::table('kpi_kbl_monthlies')->updateOrInsert(
            ['kbl_id' => $kblId, 'period' => $periodDate, 'calc_mode' => $mode],
            $payload + ['created_at' => now()]
        );

        return $payload;
    }

    // =========================================================
    // FETCHERS
    // =========================================================

    private function latestPositionDate(): ?string
    {
        return DB::table('loan_accounts')
            ->max('position_date');
    }

    private function fetchOsAndNplGlobal(string $mode, string $periodDate, array $aoCodes = []): array
    {
        if ($mode === 'eom') {
            $q = DB::table('loan_account_snapshots_monthly as m')
                ->whereDate('m.snapshot_month', $periodDate)
                ->where('m.outstanding', '>', 0);

            if (!empty($aoCodes)) {
                $q->whereIn(DB::raw("LPAD(TRIM(m.ao_code),6,'0')"), $aoCodes);
            }

            $row = $q->selectRaw("
                SUM(m.outstanding) as os_total,
                SUM(CASE WHEN m.kolek >= 3 THEN m.outstanding ELSE 0 END) as os_npl
            ")->first();

            return [(float)($row->os_total ?? 0), (float)($row->os_npl ?? 0)];
        }

        // =========================
        // REALTIME: ONLY latest position_date
        // =========================
        $latestPos = DB::table('loan_accounts')->max('position_date'); // date
        if (!$latestPos) return [0, 0];

        $q = DB::table('loan_accounts as la')
            ->whereDate('la.position_date', $latestPos)
            ->where('la.outstanding', '>', 0);

        if (!empty($aoCodes)) {
            $q->whereIn(DB::raw("LPAD(TRIM(la.ao_code),6,'0')"), $aoCodes);
        }

        $row = $q->selectRaw("
            SUM(la.outstanding) as os_total,
            SUM(CASE WHEN la.kolek >= 3 THEN la.outstanding ELSE 0 END) as os_npl
        ")->first();

        return [(float)($row->os_total ?? 0), (float)($row->os_npl ?? 0)];
    }

    private function fetchDpkMigrationGlobal(string $mode, string $baseMonth, string $curMonth, array $aoCodes = []): array
    {
        // return [base_os, mig_os, base_noa, mig_noa]
        $baseQ = DB::table('loan_account_snapshots_monthly as b')
            ->whereDate('b.snapshot_month', $baseMonth)
            ->where('b.outstanding', '>', 0)
            ->where('b.kolek', 2);

        if (!empty($aoCodes)) {
            $baseQ->whereIn(DB::raw("LPAD(TRIM(b.ao_code),6,'0')"), $aoCodes);
        }

        $baseRow = $baseQ->selectRaw("COUNT(*) as base_noa, SUM(b.outstanding) as base_os")->first();
        $baseNoa = (int)($baseRow->base_noa ?? 0);
        $baseOs  = (float)($baseRow->base_os ?? 0);

        if ($baseNoa <= 0 || $baseOs <= 0) return [0,0,0,0];

        if ($mode === 'eom') {
            $migQ = DB::table('loan_account_snapshots_monthly as b')
                ->join('loan_account_snapshots_monthly as c', function ($j) use ($curMonth) {
                    $j->on('c.account_no', '=', 'b.account_no')
                    ->whereDate('c.snapshot_month', $curMonth);
                })
                ->whereDate('b.snapshot_month', $baseMonth)
                ->where('b.kolek', 2)
                ->where('b.outstanding', '>', 0)
                ->whereRaw('COALESCE(c.kolek,0) >= 3');
        } else {

            $latestPos = $this->latestPositionDate();

            $migQ = DB::table('loan_account_snapshots_monthly as b')
                ->join('loan_accounts as la', function ($j) use ($latestPos) {
                    $j->on('la.account_no', '=', 'b.account_no')
                    ->whereDate('la.position_date', $latestPos);
                })
                ->whereDate('b.snapshot_month', $baseMonth)
                ->where('b.kolek', 2)
                ->where('b.outstanding', '>', 0)
                ->whereRaw('COALESCE(la.kolek,0) >= 3');
        }
    

        if (!empty($aoCodes)) {
            $migQ->whereIn(DB::raw("LPAD(TRIM(b.ao_code),6,'0')"), $aoCodes);
        }

        $migRow = $migQ->selectRaw("COUNT(*) as mig_noa, SUM(b.outstanding) as mig_os")->first();
        $migNoa = (int)($migRow->mig_noa ?? 0);
        $migOs  = (float)($migRow->mig_os ?? 0);

        return [$baseOs, $migOs, $baseNoa, $migNoa];
    }

    private function fetchInterestIncomeGlobal(string $mode, Carbon $period, array $aoCodes = []): float
    {
        $start = $period->copy()->startOfMonth()->toDateString();
        $end   = $period->copy()->endOfMonth()->toDateString();

        $q = DB::table('loan_installments as li')
            ->whereDate('li.paid_date', '>=', $start)
            ->whereDate('li.paid_date', '<=', $end);

        // kalau EOM, harus join snapshot bulan itu (biar ao_code sesuai bulan tsb)
        if ($mode === 'eom') {
            $periodDate = $period->copy()->startOfMonth()->toDateString();

            $q->join('loan_account_snapshots_monthly as s', function ($j) use ($periodDate) {
                $j->on('s.account_no', '=', 'li.account_no')
                ->whereDate('s.snapshot_month', $periodDate);
            });

            if (!empty($aoCodes)) {
                $q->whereIn(DB::raw("LPAD(TRIM(s.ao_code),6,'0')"), $aoCodes);
            }

        } else {
            // realtime
            $q->join('loan_accounts as la', 'la.account_no', '=', 'li.account_no');

            if (!empty($aoCodes)) {
                $q->whereIn(DB::raw("LPAD(TRIM(la.ao_code),6,'0')"), $aoCodes);
            }
        }

        $row = $q->selectRaw("SUM(COALESCE(li.interest_paid,0)) as interest_sum")->first();
        return (float)($row->interest_sum ?? 0);
    }

    private function fetchCommunityActual(Carbon $period): int
    {
        $start = $period->copy()->startOfMonth()->toDateString();
        $end   = $period->copy()->endOfMonth()->toDateString();

        return (int) DB::table('community_handlings as ch')
            // kalau mau khusus SO:
            ->where('ch.role', 'SO')
            ->whereDate('ch.period_from', '<=', $end)
            ->where(function ($q) use ($start) {
                $q->whereNull('ch.period_to')
                ->orWhereDate('ch.period_to', '>=', $start);
            })
            ->distinct('ch.community_id')
            ->count('ch.community_id');
    }

    // =========================================================
    // SCORING HELPERS
    // =========================================================

    /**
     * Achievement-based scoring:
     * 1: <85
     * 2: 85-89.99
     * 3: 90-94.99
     * 4: 95-99.99
     * 5: 100
     * 6: >100
     */
    private function scoreAchievementPct(float $pct, array $bands): int
    {
        // bands: [85,90,95,100,101] -> mapping 1..6
        if ($pct < $bands[0]) return 1;
        if ($pct < $bands[1]) return 2;
        if ($pct < $bands[2]) return 3;
        if ($pct < $bands[3]) return 4;
        if ($pct < $bands[4]) return 5;
        return 6;
    }

    /**
     * Migrasi DPK rubrik PPT (silakan adjust kalau versi final beda):
     * 1: >5%
     * 2: 4-4.99%
     * 3: 3-3.99%
     * 4: 2-2.99%
     * 5: <2%
     * 6: <1%
     */
    private function scoreMigrasiDpkPct(float $pct): int
    {
        if ($pct > 5) return 1;
        if ($pct >= 4) return 2;
        if ($pct >= 3) return 3;
        if ($pct >= 2) return 4;
        if ($pct >= 1) return 5;
        return 6;
    }

    /**
     * Pendapatan bunga rubrik PPT (achievement %):
     * 1: 0-24
     * 2: 25-49
     * 3: 50-74
     * 4: 75-99
     * 5: 100
     * 6: >100
     */
    private function scorePendapatanBungaPct(float $pct): int
    {
        if ($pct < 25) return 1;
        if ($pct < 50) return 2;
        if ($pct < 75) return 3;
        if ($pct < 100) return 4;
        if ($pct < 101) return 5;
        return 6;
    }

    /**
     * Komunitas rubrik PPT (count):
     * 1: 1
     * 2: 2
     * 3: 3
     * 4: 4
     * 5: 5
     * 6: >5
     */
    private function scoreKomunitasCount(int $n): int
    {
        if ($n <= 1) return 1;
        if ($n === 2) return 2;
        if ($n === 3) return 3;
        if ($n === 4) return 4;
        if ($n === 5) return 5;
        return 6;
    }

    private function statusFromTotal(float $total): string
    {
        // sesuaikan dengan style kamu
        if ($total >= 4.50) return 'On Track';
        if ($total >= 3.00) return 'Warning';
        return 'Critical';
    }
}