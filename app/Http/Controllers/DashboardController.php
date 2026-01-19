<?php

namespace App\Http\Controllers;

use App\Models\NplCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use App\Enums\UserRole;


class DashboardController extends Controller
{
    /**
     * ✅ 1 pintu role: supervisor boleh lihat semua, non-supervisor hanya data miliknya.
     * Rekomendasi: pindah ke User model => $user->isSupervisor()
     */

    protected function isSupervisor(): bool
    {
        $u = auth()->user();
        if (!$u) return false;

        // kalau User model sudah punya helper: isSupervisor() -> pakai itu
        if (method_exists($u, 'isSupervisor')) {
            return (bool) $u->isSupervisor();
        }

        // fallback enum-safe
        $role = $u->role(); // UserRole|null
        if (!$role) return false;

        return $role->isSupervisor(); 
        // pastikan method isSupervisor() di enum mengembalikan true untuk TL/KASI/KABAG/PE/DIR/KOM
    }


    /**
     * Subquery: ambil next_action_due dari action TERAKHIR per case.
     * (anti false overdue dari record lama)
     */
    private function lastNextDueSub(): string
    {
        return '(SELECT ca2.next_action_due
                FROM case_actions ca2
                WHERE ca2.npl_case_id = npl_cases.id
                AND ca2.next_action_due IS NOT NULL
                ORDER BY ca2.action_at DESC, ca2.id DESC
                LIMIT 1)';
    }

    /**
     * Apply scope untuk non-supervisor.
     *
     * Rules:
     * - npl_cases.assigned_to = user
     * - npl_cases.pic_user_id = user
     * - kalau user punya ao_code: loan_accounts.ao_code = user->ao_code
     * - OPTIONAL backward compat: kalau kolom npl_cases.ao_id ada, ikut dipakai (tanpa bikin error kalau kolom tidak ada)
     */
    private function applyCaseScope($q)
    {
        if ($this->isSupervisor()) {
            return $q;
        }

        $user = auth()->user();
        $uid  = (int) auth()->id();

        // employee_code = kdcollector (string '000037')
        $empCode = trim((string)($user->employee_code ?? ''));

        // cek kolom agar aman lintas schema
        $hasPicUserId     = Schema::hasColumn('npl_cases', 'pic_user_id');
        $hasLoanAoCode    = Schema::hasColumn('loan_accounts', 'ao_code');
        $hasCollectorCode = Schema::hasColumn('loan_accounts', 'collector_code');

        return $q->where(function ($w) use ($uid, $empCode, $hasPicUserId, $hasLoanAoCode, $hasCollectorCode) {

            // 1) PIC by user_id (paling benar)
            if ($hasPicUserId) {
                $w->orWhere('npl_cases.pic_user_id', $uid);
            }

            // 2) fallback by kode collector (kalau empCode tersedia)
            if ($empCode !== '' && $hasCollectorCode) {
                $w->orWhere('loan_accounts.collector_code', $empCode);
            }

            // 3) owner AO by ao_code (kalau empCode juga dipakai sbg AO code / atau user punya ao_code)
            // kalau kamu nanti tambah users.ao_code, ganti empCode dengan $user->ao_code
            if ($empCode !== '' && $hasLoanAoCode) {
                $w->orWhere('loan_accounts.ao_code', $empCode);
            }

            // safety: kalau tidak ada satupun kondisi yang kepakai, kunci kosong
            if (!$hasPicUserId && $empCode === '') {
                $w->whereRaw('1=0');
            }
        });
    }

    public function index(Request $request)
    {
        $this->authorize('viewDashboard', \App\Models\NplCase::class);

        $user = $request->user(); // ✅ FIX: definisikan $user
        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth   = now()->endOfMonth()->toDateString();
        $today        = now()->toDateString();

        /**
         * Base: join loan_accounts biar scope ao_code konsisten
         * NOTE: pakai select npl_cases.* supaya model hydration aman saat get()
         */
        $base = NplCase::query()
            ->leftJoin('loan_accounts', 'npl_cases.loan_account_id', '=', 'loan_accounts.id');

        $base = $this->applyCaseScope($base);

        // --- Ringkasan utama ---
        $totalCases = (clone $base)->count('npl_cases.id');

        $openCases = (clone $base)
            ->whereNull('npl_cases.closed_at')
            ->count('npl_cases.id');

        $closedThisMonth = (clone $base)
            ->whereNotNull('npl_cases.closed_at')
            ->whereBetween('npl_cases.closed_at', [$startOfMonth, $endOfMonth])
            ->count('npl_cases.id');

        $totalOutstandingOpen = (clone $base)
            ->whereNull('npl_cases.closed_at')
            ->sum('loan_accounts.outstanding');

        // --- Next action overdue (AKURAT: pakai last action next_due) ---
        $lastNextDueSub = $this->lastNextDueSub();

        $overdueQuery = NplCase::query()
            ->leftJoin('loan_accounts', 'npl_cases.loan_account_id', '=', 'loan_accounts.id');

        $overdueQuery = $this->applyCaseScope($overdueQuery);

        $overdueQuery
            ->whereNull('npl_cases.closed_at')
            ->whereRaw("{$lastNextDueSub} IS NOT NULL")
            ->whereRaw("{$lastNextDueSub} < ?", [$today]);

        $overdueCount = (clone $overdueQuery)->count('npl_cases.id');

        // ambil beberapa contoh (maks 5)
        // NOTE: kita select npl_cases.* agar eloquent modelnya bersih
        $overdueSamples = (clone $overdueQuery)
            ->select('npl_cases.*')
            ->with('loanAccount')
            ->orderByDesc('npl_cases.priority')
            ->orderBy('npl_cases.opened_at', 'asc')
            ->limit(5)
            ->get();

        // $ao = app(\App\Services\Org\OrgVisibilityService::class)->visibleAoCodes($user);
        // dd($user->roleValue(), count($ao), array_slice($ao, 0, 10));
        return view('dashboard', [
            'totalCases'           => $totalCases,
            'openCases'            => $openCases,
            'closedThisMonth'      => $closedThisMonth,
            'totalOutstandingOpen' => $totalOutstandingOpen,
            'overdueCount'         => $overdueCount,
            'overdueSamples'       => $overdueSamples,
        ]);
    }
}
