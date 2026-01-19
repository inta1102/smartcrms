<?php

namespace App\Http\Controllers;

use App\Models\LegalAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Database\Eloquent\Builder;


class HtMonitoringController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * ✅ 1 pintu akses via policy/gate.
     * Buat Gate: viewHtMonitoring (atau policy LegalActionPolicy@viewHtMonitoring).
     */
    private function authorizeMonitoring(): void
    {
        Gate::authorize('viewHtMonitoring'); // ✅ cocok dengan middleware can:viewHtMonitoring
    }


    private function normalizeStatuses($status): array
    {
        $statuses = [];

        if (is_array($status)) {
            $statuses = array_values(array_filter(array_map(fn ($s) => strtolower(trim((string) $s)), $status)));
        } elseif (is_string($status) && $status !== '') {
            $statuses = [strtolower(trim($status))];
        }

        return $statuses;
    }

    private function normalizeAging(string $aging): string
    {
        $allowedAging = ['all','lt7','7_30','31_90','gt90'];
        return in_array($aging, $allowedAging, true) ? $aging : 'all';
    }

    private function normalizeDateBy(string $dateBy): string
    {
        $dateBy = strtolower(trim($dateBy));

        // UI value -> kolom DB qualified
        $map = [
            'created_at'    => 'legal_actions.created_at',
            'updated_at'    => 'legal_actions.updated_at',
            'start_at'      => 'legal_actions.start_at',
            'end_at'        => 'legal_actions.end_at',
            'recovery_date' => 'legal_actions.recovery_date',
            'closed_at'     => 'legal_actions.closed_at', // optional kalau mau
        ];

        return $map[$dateBy] ?? 'legal_actions.updated_at';
    }


    /**
     * Terapkan filter yang sama ke query LegalAction (kecuali aging).
     * Supaya base & baseForAgingKpi konsisten dan tidak copy-paste.
     */
    private function applyCommonFilters($query, array $filters)
    {
        $q        = $filters['q'] ?? '';
        $statuses = $filters['statuses'] ?? [];
        $method   = $filters['method'] ?? '';
        $from     = $filters['from'] ?? null;
        $to       = $filters['to'] ?? null;

        // ✅ default qualified
        $dateBy   = $filters['dateBy'] ?? 'legal_actions.updated_at';

        if (!empty($statuses)) {
            $query->whereIn('status', $statuses);
        }

        if ($method !== '') {
            $query->whereHas('htExecution', fn ($ht) => $ht->where('method', $method));
        }

        // ✅ whereDate aman karena dateBy sudah qualified
        if ($from) $query->whereDate($dateBy, '>=', $from);
        if ($to)   $query->whereDate($dateBy, '<=', $to);

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('external_ref_no', 'like', "%{$q}%")
                ->orWhere('handler_name', 'like', "%{$q}%")
                ->orWhere('law_firm_name', 'like', "%{$q}%")
                ->orWhere('summary', 'like', "%{$q}%");

                $w->orWhereHas('legalCase', function ($lc) use ($q) {
                    $lc->where('legal_case_no', 'like', "%{$q}%")
                    ->orWhere('status', 'like', "%{$q}%")
                    ->orWhere('escalation_reason', 'like', "%{$q}%");
                });

                $w->orWhereHas('legalCase.nplCase.loanAccount', function ($la) use ($q) {
                    $la->where('customer_name', 'like', "%{$q}%")
                    ->orWhere('cif', 'like', "%{$q}%")
                    ->orWhere('account_no', 'like', "%{$q}%")
                    ->orWhere('loan_account_no', 'like', "%{$q}%")
                    ->orWhere('ao_code', 'like', "%{$q}%")
                    ->orWhere('ao_name', 'like', "%{$q}%");
                });

                $w->orWhereHas('htExecution', function ($ht) use ($q) {
                    $ht->where('method', 'like', "%{$q}%")
                    ->orWhere('ht_deed_no', 'like', "%{$q}%")
                    ->orWhere('ht_cert_no', 'like', "%{$q}%")
                    ->orWhere('land_cert_type', 'like', "%{$q}%")
                    ->orWhere('land_cert_no', 'like', "%{$q}%")
                    ->orWhere('owner_name', 'like', "%{$q}%")
                    ->orWhere('object_address', 'like', "%{$q}%")
                    ->orWhere('collateral_summary', 'like', "%{$q}%");
                });
            });
        }

        return $query;
    }

    private function applyAgingFilter($query, string $aging, string $ageBaseExpr)
    {
        if ($aging === 'all') return $query;

        return $query->whereRaw(match ($aging) {
            'lt7'   => "TIMESTAMPDIFF(DAY, {$ageBaseExpr}, NOW()) < 7",
            '7_30'  => "TIMESTAMPDIFF(DAY, {$ageBaseExpr}, NOW()) BETWEEN 7 AND 30",
            '31_90' => "TIMESTAMPDIFF(DAY, {$ageBaseExpr}, NOW()) BETWEEN 31 AND 90",
            'gt90'  => "TIMESTAMPDIFF(DAY, {$ageBaseExpr}, NOW()) > 90",
            default => "1=1",
        });
    }

    public function index(Request $request)
    {
        $this->authorizeMonitoring();

        $q      = trim((string) $request->get('q', ''));
        $status = $request->get('status');                    // string/array
        $method = trim((string) $request->get('method', ''));  // parate | bawah_tangan | ''
        $aging  = $this->normalizeAging((string) $request->get('aging', 'all'));

        $from   = $request->get('from');
        $to     = $request->get('to');
        $dateBy = $this->normalizeDateBy((string) $request->get('date_by', 'updated_at'));

        $statuses = $this->normalizeStatuses($status);

        // Basis aging (sesuai desainmu)
        $ageBaseExpr = "legal_actions.updated_at";

        $filters = compact('q', 'statuses', 'method', 'aging', 'from', 'to', 'dateBy');

        // ===== Base query =====
        $base = LegalAction::query()
            ->ht()
            ->with([
                'legalCase',
                'legalCase.nplCase',
                'legalCase.nplCase.loanAccount',
                'htExecution',
            ]);
        
        $base = $this->applyAccessScope($base);   
        $base = $this->applyCommonFilters($base, $filters);

        // ===== Aging filter untuk list =====
        $base = $this->applyAgingFilter($base, $aging, $ageBaseExpr);

        // ===== List utama =====
        $actions = (clone $base)
            ->orderByDesc($dateBy)
            ->paginate(20)
            ->withQueryString();

        // ===== KPI Status (mengikuti filter yang sama, termasuk aging kalau dipilih) =====
        $countsByStatus = (clone $base)
            ->reorder()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $kpi = (object) [
            'open_total'      => (int) ($countsByStatus['open'] ?? 0),
            'scheduled_total' => (int) ($countsByStatus['scheduled'] ?? 0),
            'executed_total'  => (int) ($countsByStatus['executed'] ?? 0),
            'settled_total'   => (int) ($countsByStatus['settled'] ?? 0),
            'closed_total'    => (int) ($countsByStatus['closed'] ?? 0),
            'cancelled_total' => (int) ($countsByStatus['cancelled'] ?? 0),
        ];

        // ===== KPI Aging (tanpa aging filter, tapi tetap mengikuti filter lain) =====
        $baseForAgingKpi = LegalAction::query()
            ->ht()
            ->with(['legalCase.nplCase.loanAccount', 'htExecution']);

        $baseForAgingKpi = $this->applyAccessScope($baseForAgingKpi); 
        $baseForAgingKpi = $this->applyCommonFilters($baseForAgingKpi, $filters);

        $agingBuckets = (clone $baseForAgingKpi)
            ->reorder()
            ->selectRaw("
                SUM(CASE WHEN TIMESTAMPDIFF(DAY, {$ageBaseExpr}, NOW()) < 7 THEN 1 ELSE 0 END) AS lt7,
                SUM(CASE WHEN TIMESTAMPDIFF(DAY, {$ageBaseExpr}, NOW()) BETWEEN 7 AND 30 THEN 1 ELSE 0 END) AS d7_30,
                SUM(CASE WHEN TIMESTAMPDIFF(DAY, {$ageBaseExpr}, NOW()) BETWEEN 31 AND 90 THEN 1 ELSE 0 END) AS d31_90,
                SUM(CASE WHEN TIMESTAMPDIFF(DAY, {$ageBaseExpr}, NOW()) > 90 THEN 1 ELSE 0 END) AS gt90
            ")
            ->first();

        $agingKpi = (object) [
            'lt7'    => (int) ($agingBuckets->lt7 ?? 0),
            'd7_30'  => (int) ($agingBuckets->d7_30 ?? 0),
            'd31_90' => (int) ($agingBuckets->d31_90 ?? 0),
            'gt90'   => (int) ($agingBuckets->gt90 ?? 0),
        ];

        return view('monitoring.ht.index', [
            'actions'        => $actions,
            'countsByStatus' => $countsByStatus,
            'kpi'            => $kpi,
            'agingKpi'       => $agingKpi,
            'filters'        => $filters,
        ]);
    }

    public function summary(Request $request)
    {
        $this->authorizeMonitoring();
        return view('monitoring.ht.summary');
    }

    public function export(Request $request)
    {
        $this->authorizeMonitoring();

        // nanti: export excel/pdf (pakai filter yang sama)
        return response()->json(['ok' => true, 'todo' => 'export']);
    }

    private function applyAccessScope(Builder $q): Builder
    {
        $u = auth()->user();
        $level = strtolower(trim((string) ($u?->roleValue() ?? '')));

        // AO: hanya lihat HT yang terkait case yang dia tangani
        if ($level === 'ao') {
            $q->whereHas('legalCase.nplCase', function ($qq) use ($u) {
                $qq->where('pic_user_id', $u->id);
            });
        }

        // role lain (TL/Kasi/Legal/Direksi) biarkan tampil lebih luas (sesuai desainmu)
        return $q;
    }

    private function applyRoleScopeHt($query)
    {
        $u = auth()->user();
        if (!$u) return $query;

        $level = strtolower(trim($u->roleValue()));

        // staff yang hanya boleh melihat case yang dia tangani
        $staffCollectorLevels = ['ao', 'so', 'fe', 'be'];

        if (in_array($level, $staffCollectorLevels, true)) {
            $query->whereHas('legalCase.nplCase', function ($qq) use ($u) {
                $qq->where('pic_user_id', $u->id);
            });
        }

        return $query;
    }

}
