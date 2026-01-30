<?php

namespace App\Services\Ews;

use App\Models\LoanAccount;
use App\Models\NplCase;
use App\Models\ActionSchedule; // kalau belum ada / beda, lihat catatan di bawah
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Builder;


class EwsSummaryService
{
    public function build(Request $request, User $user): array
    {
        // =========================
        // 1) Filter dasar
        // =========================

        $dpdDist = [];
        $kolekDist = [];
        $scope = $this->visibleAoCodesFor($user); // atau default "Unknown"
        $topRiskAccounts = [];
        $topExposureRiskAccounts = [];
        $top10RestrukRisk = [];

        $latestDate = LoanAccount::max('position_date');
        $positionDate = $request->string('position_date')->toString() ?: $latestDate;

        // jika format kacau, fallback latest
        try {
            $positionDate = Carbon::parse($positionDate)->format('Y-m-d');
        } catch (\Throwable $e) {
            $positionDate = $latestDate;
        }

        $branchCode = $request->string('branch_code')->toString() ?: null;
        $aoCode     = $request->string('ao_code')->toString() ?: null;

        // =========================
        // 2) Visibility AO codes (RBAC)
        // =========================
        $visibleAoCodes = $this->visibleAoCodesFor($user);

        // Kalau user role yg boleh lihat semua, function bisa return null (artinya no filter)
        // Kalau return [] artinya tidak boleh lihat apa pun → kosongkan KPI
        if (is_array($visibleAoCodes) && empty($visibleAoCodes)) {
            return $this->emptySummaryPayload($positionDate,$branchCode,$aoCode,$latestDate,$user);
        }

        // =========================
        // 3) Base query loan_accounts (position_date + visibility + filter)
        // =========================
        $q = LoanAccount::query()
            ->whereDate('position_date', $positionDate);

        if ($branchCode) {
            $q->where('branch_code', $branchCode);
        }

        if ($aoCode) {
            $q->where('ao_code', $aoCode);
        }

        if (is_array($visibleAoCodes)) {
            $q->whereIn('ao_code', $visibleAoCodes);
        }

        // =========================
        // 4) KPI Cards (agregasi)
        // =========================
        // Catatan: pakai clone agar tidak saling ganggu condition
        $totalOS = (clone $q)->sum('outstanding');

        $osDpd15 = (clone $q)->where('dpd', '>', 15)->sum('outstanding');

        $osKolek3 = (clone $q)->where('kolek', '>=', 3)->sum('outstanding');

        $osRestruk = (clone $q)->where('is_restructured', 1)->sum('outstanding');

        // #Case open (berdasarkan npl_cases) — join ke loan_accounts agar konsisten filter
        // Asumsi: npl_cases ada kolom loan_account_id dan status/closed_at/is_active
        $caseOpen = $this->countOpenCases($positionDate, $branchCode, $aoCode, $visibleAoCodes);

        // #Agenda overdue (kalau kamu pakai ActionSchedule)
        $agendaOverdue = $this->countOverdueSchedules($positionDate, $branchCode, $aoCode, $visibleAoCodes);

        // Rasio cepat buat indikator (dipakai nanti buat RED/AMBER/GREEN)
        $pctKolek3 = $totalOS > 0 ? ($osKolek3 / $totalOS) : 0.0;
        $pctDpd15  = $totalOS > 0 ? ($osDpd15 / $totalOS) : 0.0;
        $pctRs     = $totalOS > 0 ? ($osRestruk / $totalOS) : 0.0;

        // =========================
        // 5) Distribution (DPD & KOLEK)
        // =========================
        $dpdDist   = $this->buildDpdDistribution($q);
        $kolekDist = $this->buildKolekDistribution($q);

        // tambahkan ratio terhadap totalOS supaya Direksi langsung paham porsi
        $dpdDist = array_map(function ($r) use ($totalOS) {
            $r['ratio'] = $totalOS > 0 ? ($r['os'] / $totalOS) : 0.0;
            return $r;
        }, $dpdDist);

        $kolekDist = array_map(function ($r) use ($totalOS) {
            $r['ratio'] = $totalOS > 0 ? ($r['os'] / $totalOS) : 0.0;
            return $r;
        }, $kolekDist);

        $scope = $this->visibilityScopeLabel($user, $visibleAoCodes);

        $cards = [
            [
                'label' => 'Total OS',
                'value' => $totalOS,
                'hint'  => 'Outstanding seluruh kredit sesuai filter',
            ],
            [
                'label' => 'OS DPD > 15',
                'value' => $osDpd15,
                'hint'  => 'Early delinquency (DPD mulai rawan)',
                'meta'  => ['ratio' => $pctDpd15],
            ],
            [
                'label' => 'OS Kolek ≥ 3',
                'value' => $osKolek3,
                'hint'  => 'NPL risk utama (kualitas memburuk)',
                'meta'  => ['ratio' => $pctKolek3],
            ],
            [
                'label' => 'OS Restruktur',
                'value' => $osRestruk,
                'hint'  => 'Perlu monitoring aktif (agenda WA/Call/Visit)',
                'meta'  => ['ratio' => $pctRs],
            ],
            [
                'label' => '# Case Open',
                'value' => $caseOpen,
                'hint'  => 'Jumlah case aktif di CRMS',
            ],
            [
                'label' => '# Agenda Overdue',
                'value' => $agendaOverdue,
                'hint'  => 'Agenda yang lewat jatuh tempo & belum selesai',
            ],
        ];

        // =========================
        // 6) Top 10 rekening risiko
        // =========================
        $topRiskAccounts = $this->buildTopRiskAccounts($q, 10);

        // =========================
        // 7) Top 10 exposure risiko (OS terbesar)
        // =========================
        $topExposureRiskAccounts = $this->buildTopExposureRiskAccounts($q, 10);

        $top10RestrukRisk = $this->top10RestructureHighRisk($q, 10);

        return [
            'filters' => compact('positionDate', 'branchCode', 'aoCode'),
            'latestDate' => $latestDate,
            'cards' => $cards,
            'dpdDist' => $dpdDist,
            'kolekDist' => $kolekDist,
            'scope' => $scope,
            'topRiskAccounts' => $topRiskAccounts,
            'topExposureRiskAccounts' => $topExposureRiskAccounts,
            'top10RestrukRisk' => $top10RestrukRisk,
        ];

    }

    protected function visibilityScopeLabel(User $user, ?array $visibleAoCodes): string
    {
        if ($visibleAoCodes === null) return 'ALL';
        $n = count($visibleAoCodes);

        if ($n <= 1) return 'PERSONAL';
        return "SUBSET ({$n} AO)";
    }

    /**
     * RBAC: kembalikan:
     * - null => boleh lihat semua
     * - array => daftar ao_code yang boleh dilihat
     * - [] => tidak boleh lihat apa pun
     */
    protected function visibleAoCodesFor(User $user): ?array
    {
        // ✅ sesuaikan dengan helper role kamu
        // Kalau Direksi/Kabag/PE boleh lihat semua:
        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['DIREKSI','DIR','KOM','KABAG','KBL','PE'])) {
            return null;
        }

        // AO/Collector hanya lihat dirinya
        // NOTE: kamu bisa sesuaikan mapping employee_code <-> ao_code
        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['AO','BE','FE','SO','RO','SA'])) {
            return $user->employee_code ? [trim((string)$user->employee_code)] : [];
        }

        // TL/Kasi: ambil bawahan dari org_assignments (leader_id = user->id)
        // Pastikan model OrgAssignment ada di project kamu
        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['TL','TLL','TLR','KSL','KSR','KSO','KSA'])) {
            if (!class_exists(\App\Models\OrgAssignment::class)) {
                return [];
            }

            $aoCodes = \App\Models\OrgAssignment::query()
                ->where('leader_id', $user->id)
                ->join('users', 'users.id', '=', 'org_assignments.user_id')
                ->whereNotNull('users.employee_code')
                ->pluck('users.employee_code')
                ->map(fn($v) => trim((string)$v))
                ->filter()
                ->unique()
                ->values()
                ->all();

            return $aoCodes;
        }

        // Default aman: kosong
        return [];
    }

    protected function countOpenCases(string $positionDate, ?string $branchCode, ?string $aoCode, ?array $visibleAoCodes): int
    {
        // Kalau tabel npl_cases tidak punya status, kita fallback "closed_at is null" bila ada
        $q = NplCase::query()
            ->join('loan_accounts', 'loan_accounts.id', '=', 'npl_cases.loan_account_id')
            ->whereDate('loan_accounts.position_date', $positionDate);

        if ($branchCode) $q->where('loan_accounts.branch_code', $branchCode);
        if ($aoCode)     $q->where('loan_accounts.ao_code', $aoCode);
        if (is_array($visibleAoCodes)) $q->whereIn('loan_accounts.ao_code', $visibleAoCodes);

        if (Schema::hasColumn('npl_cases', 'is_active')) {
            $q->where('npl_cases.is_active', 1);
        } elseif (Schema::hasColumn('npl_cases', 'status')) {
            $q->whereIn('npl_cases.status', ['open','active','ongoing']);
        } elseif (Schema::hasColumn('npl_cases', 'closed_at')) {
            $q->whereNull('npl_cases.closed_at');
        }

        return (int) $q->distinct('npl_cases.id')->count('npl_cases.id');
    }

    protected function countOverdueSchedules(string $positionDate, ?string $branchCode, ?string $aoCode, ?array $visibleAoCodes): int
    {
        if (!class_exists(\App\Models\ActionSchedule::class)) return 0;

        $q = \App\Models\ActionSchedule::query();

        // ✅ overdue filter (selalu prefix tabel)
        if (Schema::hasColumn('action_schedules', 'status')) {
            $q->where('action_schedules.status', 'pending');
        }

        if (Schema::hasColumn('action_schedules', 'due_at')) {
            $q->where('action_schedules.due_at', '<', now());
        } elseif (Schema::hasColumn('action_schedules', 'scheduled_at')) {
            $q->where('action_schedules.scheduled_at', '<', now());
        }

        // ✅ Join untuk bisa filter position_date + ao/branch
        if (Schema::hasColumn('action_schedules', 'npl_case_id')) {
            $q->join('npl_cases', 'npl_cases.id', '=', 'action_schedules.npl_case_id')
            ->join('loan_accounts', 'loan_accounts.id', '=', 'npl_cases.loan_account_id')
            ->whereDate('loan_accounts.position_date', $positionDate);
        } elseif (Schema::hasColumn('action_schedules', 'loan_account_id')) {
            $q->join('loan_accounts', 'loan_accounts.id', '=', 'action_schedules.loan_account_id')
            ->whereDate('loan_accounts.position_date', $positionDate);
        } else {
            // fallback: tidak bisa join untuk filter
            return (int) $q->distinct('action_schedules.id')->count('action_schedules.id');
        }

        if ($branchCode) $q->where('loan_accounts.branch_code', $branchCode);
        if ($aoCode)     $q->where('loan_accounts.ao_code', $aoCode);
        if (is_array($visibleAoCodes)) $q->whereIn('loan_accounts.ao_code', $visibleAoCodes);

        return (int) $q->distinct('action_schedules.id')->count('action_schedules.id');
    }

    protected function emptyCards(): array
    {
        return [
            ['label' => 'Total OS', 'value' => 0, 'hint' => ''],
            ['label' => 'OS DPD > 15', 'value' => 0, 'hint' => ''],
            ['label' => 'OS Kolek ≥ 3', 'value' => 0, 'hint' => ''],
            ['label' => 'OS Restruktur', 'value' => 0, 'hint' => ''],
            ['label' => '# Case Open', 'value' => 0, 'hint' => ''],
            ['label' => '# Agenda Overdue', 'value' => 0, 'hint' => ''],
        ];
    }

    protected function buildDpdDistribution($baseQ): array
    {
        $rows = (clone $baseQ)
            ->selectRaw("
                CASE
                    WHEN dpd <= 15 THEN '0-15'
                    WHEN dpd BETWEEN 16 AND 29 THEN '16-29'
                    WHEN dpd BETWEEN 30 AND 59 THEN '30-59'
                    ELSE '60+'
                END AS bucket,
                COUNT(*) AS cnt,
                SUM(outstanding) AS os
            ")
            ->groupBy('bucket')
            ->get();

        // urutan fix biar tampil konsisten
        $order = ['0-15','16-29','30-59','60+'];

        $map = $rows->keyBy('bucket');

        $out = [];
        foreach ($order as $b) {
            $out[] = [
                'bucket' => $b,
                'count'  => (int)($map[$b]->cnt ?? 0),
                'os'     => (float)($map[$b]->os ?? 0),
            ];
        }

        return $out;
    }

    protected function buildKolekDistribution($baseQ): array
    {
        $rows = (clone $baseQ)
            ->selectRaw("
                COALESCE(kolek, 0) AS bucket,
                COUNT(*) AS cnt,
                SUM(outstanding) AS os
            ")
            ->groupBy('bucket')
            ->get();

        // kolek normal 1-5, tapi kita tetap siap kalau ada 0/null
        $order = [1,2,3,4,5];

        $map = $rows->keyBy('bucket');

        $out = [];
        foreach ($order as $k) {
            $out[] = [
                'bucket' => (string)$k,
                'count'  => (int)($map[$k]->cnt ?? 0),
                'os'     => (float)($map[$k]->os ?? 0),
            ];
        }

        return $out;
    }

    protected function buildTopRiskAccounts($baseQ, int $limit = 10): array
    {
        // Risk score sederhana tapi kuat (bisa kita tune nanti)
        // - kolek >=3 => +100
        // - dpd >=60  => +80
        // - dpd 30-59 => +60
        // - dpd 16-29 => +40
        // - restruk   => +50
        // - restruk freq >=2 => +20
        // - log10(OS) * 10 (biar OS besar naik)
        //
        // NOTE: LOG10 di MySQL ada, tapi kita pakai IF(outstanding>0, ...) aman.
        $rows = (clone $baseQ)
            ->select([
                'loan_accounts.id',
                'loan_accounts.account_no',
                'loan_accounts.customer_name',
                'loan_accounts.ao_code',
                'loan_accounts.ao_name',
                'loan_accounts.branch_code',
                'loan_accounts.branch_name',
                'loan_accounts.kolek',
                'loan_accounts.dpd',
                'loan_accounts.outstanding',
                'loan_accounts.is_restructured',
                'loan_accounts.restructure_freq',
                'loan_accounts.last_restructure_date',
                'loan_accounts.installment_day',
                'loan_accounts.last_payment_date',
            ])
            ->selectRaw("
                (
                    CASE WHEN loan_accounts.kolek >= 3 THEN 100 ELSE 0 END
                + CASE WHEN loan_accounts.dpd >= 60 THEN 80
                        WHEN loan_accounts.dpd BETWEEN 30 AND 59 THEN 60
                        WHEN loan_accounts.dpd BETWEEN 16 AND 29 THEN 40
                        ELSE 0 END
                + CASE WHEN loan_accounts.is_restructured = 1 THEN 50 ELSE 0 END
                + CASE WHEN loan_accounts.restructure_freq >= 2 THEN 20 ELSE 0 END
                + (CASE WHEN loan_accounts.outstanding > 0 THEN (LOG10(loan_accounts.outstanding + 1) * 10) ELSE 0 END)
                ) AS risk_score
            ")
            ->orderByDesc('risk_score')
            ->orderByDesc('loan_accounts.outstanding')
            ->limit($limit)
            ->get();

        return $rows->map(function ($r) {
            return [
                'account_no' => $r->account_no,
                'customer_name' => $r->customer_name,
                'branch' => trim(($r->branch_name ?: '') . ' (' . ($r->branch_code ?: '-') . ')'),
                'ao' => trim(($r->ao_name ?: '') . ' (' . ($r->ao_code ?: '-') . ')'),
                'kolek' => (int)($r->kolek ?? 0),
                'dpd' => (int)($r->dpd ?? 0),
                'os' => (float)($r->outstanding ?? 0),
                'is_restructured' => (int)($r->is_restructured ?? 0) === 1,
                'restructure_freq' => (int)($r->restructure_freq ?? 0),
                'last_restructure_date' => $r->last_restructure_date,
                'installment_day' => $r->installment_day,
                'last_payment_date' => $r->last_payment_date,
                'risk_score' => (float)($r->risk_score ?? 0),
            ];
        })->all();
    }

    protected function buildTopExposureRiskAccounts($baseQ, int $limit = 10): array
    {
        $rows = (clone $baseQ)
            ->where(function ($w) {
                $w->where('loan_accounts.dpd', '>', 15)
                ->orWhere('loan_accounts.is_restructured', 1)
                ->orWhere('loan_accounts.kolek', '>=', 2);
            })
            ->select([
                'loan_accounts.account_no',
                'loan_accounts.customer_name',
                'loan_accounts.ao_code',
                'loan_accounts.ao_name',
                'loan_accounts.branch_code',
                'loan_accounts.branch_name',
                'loan_accounts.kolek',
                'loan_accounts.dpd',
                'loan_accounts.outstanding',
                'loan_accounts.is_restructured',
                'loan_accounts.restructure_freq',
                'loan_accounts.last_restructure_date',
                'loan_accounts.installment_day',
                'loan_accounts.last_payment_date',
            ])
            ->selectRaw("
                CASE
                    WHEN loan_accounts.kolek >= 3 THEN 'KOLEK>=3'
                    WHEN loan_accounts.dpd > 15 THEN 'DPD>15'
                    WHEN loan_accounts.is_restructured = 1 THEN 'RESTRUK'
                    WHEN loan_accounts.kolek >= 2 THEN 'KOLEK=2'
                    ELSE 'MONITOR'
                END AS reason
            ")
            ->orderByDesc('loan_accounts.outstanding')
            ->limit($limit)
            ->get();

        return $rows->map(function ($r) {
            return [
                'account_no' => $r->account_no,
                'customer_name' => $r->customer_name,
                'branch' => trim(($r->branch_name ?: '') . ' (' . ($r->branch_code ?: '-') . ')'),
                'ao' => trim(($r->ao_name ?: '') . ' (' . ($r->ao_code ?: '-') . ')'),
                'kolek' => (int)($r->kolek ?? 0),
                'dpd' => (int)($r->dpd ?? 0),
                'os' => (float)($r->outstanding ?? 0),
                'is_restructured' => (int)($r->is_restructured ?? 0) === 1,
                'restructure_freq' => (int)($r->restructure_freq ?? 0),
                'last_restructure_date' => $r->last_restructure_date,
                'installment_day' => $r->installment_day,
                'last_payment_date' => $r->last_payment_date,
                'reason' => (string)($r->reason ?? 'MONITOR'),
            ];
        })->all();
    }

    public function restructureBuckets(array $filter): Collection
    {
        $q = LoanAccount::query()
            ->where('is_restructured', 1)
            ->whereDate('position_date', $filter['position_date']);

        if (!empty($filter['branch_code'])) {
            $q->where('branch_code', $filter['branch_code']);
        }

        if (!empty($filter['ao_code'])) {
            $q->where('ao_code', $filter['ao_code']);
        }

        if (!empty($filter['visible_ao_codes'])) {
            $q->whereIn('ao_code', $filter['visible_ao_codes']);
        }

        return $q->selectRaw("
            CASE
                WHEN last_restructure_date IS NOT NULL
                AND DATEDIFF(position_date, last_restructure_date) <= 30
                    THEN 'R0-30'
                WHEN last_restructure_date IS NOT NULL
                AND DATEDIFF(position_date, last_restructure_date) BETWEEN 31 AND 90
                    THEN 'R31-90'
                WHEN last_restructure_date IS NOT NULL
                AND DATEDIFF(position_date, last_restructure_date) > 90
                    THEN 'R>90'
                ELSE 'R-NULL'
            END AS bucket,
            COUNT(*) AS rek,
            SUM(outstanding) AS os
        ")
        ->groupBy('bucket')
        ->orderByRaw("
            FIELD(bucket,'R0-30','R31-90','R>90','R-NULL')
        ")
        ->get();
    }

    public function top10RestructureHighRisk(Builder $q, int $limit = 10): Collection
    {
        // pastikan query base sudah ada filter position_date/is_active/visibility
        $qq = (clone $q)
            ->where('loan_accounts.is_restructured', 1)
            ->whereNotNull('loan_accounts.last_restructure_date')
            ->whereRaw('DATEDIFF(loan_accounts.position_date, loan_accounts.last_restructure_date) BETWEEN 0 AND 30')
            ->where('loan_accounts.dpd', '>', 0) // proxy "DPD naik" versi snapshot tunggal
            ->orderByDesc('loan_accounts.dpd')
            ->orderByDesc('loan_accounts.outstanding')
            ->limit($limit);

        return $qq->get([
            'loan_accounts.account_no',
            'loan_accounts.customer_name',
            'loan_accounts.ao_code',
            'loan_accounts.ao_name',
            'loan_accounts.kolek',
            'loan_accounts.dpd',
            'loan_accounts.outstanding',
            'loan_accounts.is_restructured',
            'loan_accounts.restructure_freq',
            'loan_accounts.last_restructure_date',
        ])->map(function ($row) {
            // bucket R0-30 (selalu true di query ini, tapi tetap aman)
            $row->restruk_bucket = 'R0-30';
            $row->reason = 'R0-30 + DPD>0';
            return $row;
        });
    }

    protected function emptySummaryPayload($positionDate, $branchCode, $aoCode, $latestDate, $user): array
    {
        return [
            'filters' => compact('positionDate', 'branchCode', 'aoCode'),
            'latestDate' => $latestDate,
            'cards' => $this->emptyCards(),
            'dpdDist' => [],
            'kolekDist' => [],
            'scope' => $this->scopeLabelForEws($user),
            'topRiskAccounts' => [],
            'topExposureRiskAccounts' => [],
            'top10RestrukRisk' => [],
        ];
    }

    protected function scopeLabelForEws(\App\Models\User $user): string
    {
        // label untuk info di UI (bukan untuk security)
        if (method_exists($user, 'hasAnyRole')) {
            if ($user->hasAnyRole(['DIR','KOM','KDK','AUD','MR','KPI','KTI','TI'])) return 'ALL (Pimpinan)';
            if ($user->hasAnyRole(['KBO','KSA','SAD','KSL','KSR','KSO'])) return 'UNIT / BAGIAN';
            if ($user->hasAnyRole(['TL','TLL','TLR'])) return 'TIM (Bawahan)';
            if ($user->hasAnyRole(['AO','RO','SO','BE','FE','SA'])) return 'PERSONAL (Milik sendiri)';
        }

        return 'UNKNOWN';
    }

}
