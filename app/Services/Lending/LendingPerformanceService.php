<?php

namespace App\Services\Lending;

use App\Models\LoanAccount;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LendingPerformanceService
{
    protected string $scheduleDateColumn = 'scheduled_at';

    public function latestPositionDate(): ?string
    {
        return LoanAccount::query()
            ->selectRaw('MAX(position_date) as d')
            ->value('d');
    }

    /**
     * Visibility AO:
     * - null => ALL
     * - []   => NONE
     * - ['000047', ...] => subset
     *
     * NOTE: Silakan sambungkan ke OrgVisibilityService yang sudah Bro punya.
     */
    public function visibleAoCodesForUser($user): ?array
    {
        // === TEMP SEMENTARA (PENTING) ===
        // Kalau sudah ada OrgVisibilityService di CRMS,
        // ganti ini jadi $this->orgVis->visibleAoCodes($user) atau yang Bro pakai.
        return null;
    }

    public function emptySummary(): array
    {
        return [
            'total_os'      => 0,
            'noa'           => 0,
            'npl_os'        => 0,
            'npl_ratio'     => 0,
            'dpd_gt_0_os'   => 0,
            'dpd_gt_30_os'  => 0,
            'dpd_gt_60_os'  => 0,
            'dpd_gt_90_os'  => 0,
        ];
    }

    /**
     * Base query loan_accounts untuk snapshot + filter branch/ao + visibility.
     */
    protected function baseLoanQuery(array $filter, ?array $visibleAoCodes)
    {
        $q = LoanAccount::query()
            ->whereDate('position_date', $filter['position_date']);

        if (!empty($filter['branch_code'])) {
            $q->where('branch_code', $filter['branch_code']);
        }

        if (!empty($filter['ao_code'])) {
            $q->where('ao_code', $filter['ao_code']);
        }

        if (is_array($visibleAoCodes)) {
            $q->whereIn('ao_code', $visibleAoCodes);
        }

        return $q;
    }

    /**
     * Summary cards: OS/NOA/NPL/DPD buckets.
     */
    public function summary(array $filter, ?array $visibleAoCodes): array
    {
        $q = $this->baseLoanQuery($filter, $visibleAoCodes);

        // Agregasi sekali jalan (lebih cepat)
        $row = (clone $q)
            ->selectRaw('
                COALESCE(SUM(outstanding),0) as total_os,
                COUNT(*) as noa,

                COALESCE(SUM(CASE WHEN kolek IN (3,4,5) THEN outstanding ELSE 0 END),0) as npl_os,

                COALESCE(SUM(CASE WHEN dpd > 0  THEN outstanding ELSE 0 END),0) as dpd_gt_0_os,
                COALESCE(SUM(CASE WHEN dpd > 30 THEN outstanding ELSE 0 END),0) as dpd_gt_30_os,
                COALESCE(SUM(CASE WHEN dpd > 60 THEN outstanding ELSE 0 END),0) as dpd_gt_60_os,
                COALESCE(SUM(CASE WHEN dpd > 90 THEN outstanding ELSE 0 END),0) as dpd_gt_90_os
            ')
            ->first();

        $totalOs  = (float) ($row->total_os ?? 0);
        $nplOs    = (float) ($row->npl_os ?? 0);
        $nplRatio = $totalOs > 0 ? ($nplOs / $totalOs) : 0;

        return [
            'total_os'      => $totalOs,
            'noa'           => (int) ($row->noa ?? 0),
            'npl_os'        => $nplOs,
            'npl_ratio'     => $nplRatio,
            'dpd_gt_0_os'   => (float) ($row->dpd_gt_0_os ?? 0),
            'dpd_gt_30_os'  => (float) ($row->dpd_gt_30_os ?? 0),
            'dpd_gt_60_os'  => (float) ($row->dpd_gt_60_os ?? 0),
            'dpd_gt_90_os'  => (float) ($row->dpd_gt_90_os ?? 0),
        ];
    }

    /**
     * Ranking AO: OS, NPL%, DPD buckets, overdue agenda.
     * Overdue agenda dihitung dari ActionSchedule yang terhubung ke NplCase->LoanAccount.
     *
     * Catatan: Sesuaikan nama tabel/kolom jika berbeda.
     */
    public function rankingAo(array $filter, ?array $visibleAoCodes): Collection
    {
        $positionDate = $filter['position_date'];

        // Subquery overdue agenda per ao_code
        // Asumsi:
        // action_schedules.npl_case_id -> npl_cases.id
        // npl_cases.loan_account_id -> loan_accounts.id
        // loan_accounts.ao_code ada
        $overdueSub = DB::table('action_schedules as s')
            ->join('npl_cases as c', 'c.id', '=', 's.npl_case_id')
            ->join('loan_accounts as la', 'la.id', '=', 'c.loan_account_id')
            ->whereDate('la.position_date', $positionDate)

            // ✅ overdue = scheduled_at sudah lewat hari ini
            ->whereDate("s.{$this->scheduleDateColumn}", '<', now()->toDateString())

            // ✅ belum selesai
            ->whereIn('s.status', ['pending', 'escalated'])

            ->selectRaw('la.ao_code, COUNT(*) as overdue_count')
            ->groupBy('la.ao_code');


        // Base loan query untuk agregasi per AO
        $q = $this->baseLoanQuery($filter, $visibleAoCodes);

        $rows = (clone $q)
            ->selectRaw('
                ao_code,
                COALESCE(SUM(outstanding),0) as os,
                COUNT(*) as noa,

                COALESCE(SUM(CASE WHEN kolek IN (3,4,5) THEN outstanding ELSE 0 END),0) as npl_os,
                COALESCE(SUM(CASE WHEN dpd > 30 THEN outstanding ELSE 0 END),0) as dpd_gt_30_os,
                COALESCE(SUM(CASE WHEN dpd > 60 THEN outstanding ELSE 0 END),0) as dpd_gt_60_os,
                COALESCE(SUM(CASE WHEN dpd > 90 THEN outstanding ELSE 0 END),0) as dpd_gt_90_os
            ')
            ->groupBy('ao_code')
            ->orderByDesc('os')
            ->get();

        // Map: tambah nama AO + overdue + lampu
        $aoCodes = $rows->pluck('ao_code')->filter()->unique()->values();

        // Optional: ambil nama AO dari tabel users jika ada mapping ao_code
        $names = User::query()
            ->whereIn('ao_code', $aoCodes)
            ->pluck('name', 'ao_code');

        // Overdue map
        $overdue = DB::query()
            ->fromSub($overdueSub, 'x')
            ->pluck('overdue_count', 'ao_code');

        return $rows->map(function ($r) use ($names, $overdue) {
            $os      = (float) $r->os;
            $nplOs   = (float) $r->npl_os;
            $nplPct  = $os > 0 ? ($nplOs / $os) : 0;

            $dpd90Os = (float) $r->dpd_gt_90_os;
            $dpd60Os = (float) $r->dpd_gt_60_os;
            $dpd30Os = (float) $r->dpd_gt_30_os;

            $overdueCount = (int) ($overdue[$r->ao_code] ?? 0);

            // Lampu sederhana (bisa kita refine nanti)
            // Merah: NPL% >= 5% atau DPD>90 ada atau overdue agenda banyak
            // Kuning: NPL% 3-5% atau DPD>60 ada
            // Hijau: lainnya
            $lamp = 'green';
            if ($nplPct >= 0.05 || $dpd90Os > 0 || $overdueCount >= 10) {
                $lamp = 'red';
            } elseif ($nplPct >= 0.03 || $dpd60Os > 0 || $overdueCount >= 5) {
                $lamp = 'yellow';
            }

            return (object) [
                'ao_code'       => $r->ao_code,
                'ao_name'       => $names[$r->ao_code] ?? '-',
                'os'            => $os,
                'noa'           => (int) $r->noa,

                'npl_os'        => $nplOs,
                'npl_pct'       => $nplPct,

                'dpd_gt_30_os'  => $dpd30Os,
                'dpd_gt_60_os'  => $dpd60Os,
                'dpd_gt_90_os'  => $dpd90Os,

                'overdue_count' => $overdueCount,
                'lamp'          => $lamp,
            ];
        });
    }

    public function branchOptions(string $positionDate): array
    {
        return LoanAccount::query()
            ->whereDate('position_date', $positionDate)
            ->select('branch_code')
            ->whereNotNull('branch_code')
            ->groupBy('branch_code')
            ->orderBy('branch_code')
            ->pluck('branch_code')
            ->all();
    }

    public function aoOptions(string $positionDate, ?string $branchCode, ?array $visibleAoCodes): array
    {
        $q = LoanAccount::query()
            ->whereDate('position_date', $positionDate)
            ->select('ao_code')
            ->whereNotNull('ao_code');

        if ($branchCode) {
            $q->where('branch_code', $branchCode);
        }
        if (is_array($visibleAoCodes)) {
            $q->whereIn('ao_code', $visibleAoCodes);
        }

        return $q->groupBy('ao_code')
            ->orderBy('ao_code')
            ->pluck('ao_code')
            ->all();
    }

    public function rootCauseAo(array $filter, ?array $visibleAoCodes): array
    {
        $positionDate = $filter['position_date'];
        $aoCode       = $filter['ao_code'];

        // 1) Summary AO (pakai baseLoanQuery)
        $summary = $this->summary($filter, $visibleAoCodes);

        // 2) Overdue agenda list (pending/escalated && scheduled_at < today)
        $overdueAgendas = DB::table('action_schedules as s')
            ->join('npl_cases as c', 'c.id', '=', 's.npl_case_id')
            ->join('loan_accounts as la', 'la.id', '=', 'c.loan_account_id')
            ->whereDate('la.position_date', $positionDate)
            ->where('la.ao_code', $aoCode)
            ->when(!empty($filter['branch_code']), fn($q) => $q->where('la.branch_code', $filter['branch_code']))
            ->whereIn('s.status', ['pending', 'escalated'])
            ->whereDate('s.scheduled_at', '<', now()->toDateString())
            ->orderBy('s.scheduled_at')
            ->select([
                's.id',
                's.type',
                's.level',
                's.title',
                's.status',
                's.scheduled_at',
                's.assigned_to',
                'c.id as npl_case_id',
                'la.id as loan_account_id',
                'la.outstanding',
                'la.dpd',
                'la.kolek',
                // kolom identitas (sesuaikan kalau beda)
                DB::raw('la.account_no as account_no'),
                DB::raw('la.customer_name as customer_name'),
            ])
            ->limit(200)
            ->get();

        // 3) Escalated agenda list (status=escalated)
        $escalatedAgendas = DB::table('action_schedules as s')
            ->join('npl_cases as c', 'c.id', '=', 's.npl_case_id')
            ->join('loan_accounts as la', 'la.id', '=', 'c.loan_account_id')
            ->whereDate('la.position_date', $positionDate)
            ->where('la.ao_code', $aoCode)
            ->when(!empty($filter['branch_code']), fn($q) => $q->where('la.branch_code', $filter['branch_code']))
            ->where('s.status', 'escalated')
            ->orderByDesc('s.escalated_at')
            ->select([
                's.id','s.type','s.level','s.title','s.status','s.scheduled_at','s.escalated_at','s.escalation_note',
                'c.id as npl_case_id',
                'la.outstanding','la.dpd','la.kolek',
                DB::raw('la.account_no as account_no'),
                DB::raw('la.customer_name as customer_name'),
            ])
            ->limit(200)
            ->get();

        // 4) Top DPD>90 (root cause risk paling keras)
        $topDpd90 = LoanAccount::query()
            ->whereDate('position_date', $positionDate)
            ->where('ao_code', $aoCode)
            ->when(!empty($filter['branch_code']), fn($q) => $q->where('branch_code', $filter['branch_code']))
            ->where('dpd', '>', 90)
            ->orderByDesc('outstanding')
            ->limit(50)
            ->get([
                'id','branch_code','ao_code','outstanding','dpd','kolek',
                // sesuaikan
                DB::raw('account_no as account_no'),
                DB::raw('customer_name as customer_name'),
                DB::raw('cif as cif'),
            ]);

        // 5) Top NPL (kolek 3-5)
        $topNpl = LoanAccount::query()
            ->whereDate('position_date', $positionDate)
            ->where('ao_code', $aoCode)
            ->when(!empty($filter['branch_code']), fn($q) => $q->where('branch_code', $filter['branch_code']))
            ->whereIn('kolek', [3,4,5])
            ->orderByDesc('outstanding')
            ->limit(50)
            ->get([
                'id','branch_code','ao_code','outstanding','dpd','kolek',
                DB::raw('account_no as account_no'),
                DB::raw('customer_name as customer_name'),
                DB::raw('cif as cif'),
            ]);

        return [
            'summary'          => $summary,
            'overdue_agendas'  => $overdueAgendas,
            'escalated_agendas'=> $escalatedAgendas,
            'top_dpd90'        => $topDpd90,
            'top_npl'          => $topNpl,
        ];
    }

}
