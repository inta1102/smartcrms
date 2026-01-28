<?php

namespace App\Services\Kpi;

use App\Models\LoanAccount;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MarketingOsResolver
{
    /**
     * Resolve OS end untuk 1 AO pada 1 periode (startOfMonth).
     * Prioritas: snapshot akhir bulan (jika ada) -> loan_accounts last position_date dalam bulan tsb.
     *
     * @return array{os_end: float, source: string, position_date_used: ?string}
     */
    public function resolveOsEnd(User $user, Carbon $period): array
    {
        $aoCode = (string)($user->ao_code ?? '');
        if ($aoCode === '') {
            return ['os_end' => 0.0, 'source' => 'none', 'position_date_used' => null];
        }

        $period = $period->copy()->startOfMonth();
        $monthStart = $period->copy()->startOfMonth()->toDateString();
        $monthEnd   = $period->copy()->endOfMonth()->toDateString();

        // =========================
        // 1) Coba snapshot akhir bulan
        // =========================
        // Contoh: tabel snapshot: loan_monthly_snapshots
        // kolom: period (YYYY-MM-01), ao_code, os_end
        $snapOs = DB::table('loan_monthly_snapshots')
            ->whereDate('period', $monthStart)
            ->where('ao_code', $aoCode)
            ->value('os_end');

        if ($snapOs !== null) {
            return [
                'os_end' => (float)$snapOs,
                'source' => 'snapshot',
                'position_date_used' => $monthEnd, // snapshot merepresentasikan akhir bulan
            ];
        }

        // =========================
        // 2) Fallback: loan_accounts (posisi terakhir yang ada dalam bulan)
        // =========================
        $lastDate = LoanAccount::query()
            ->where('ao_code', $aoCode)
            ->whereDate('position_date', '>=', $monthStart)
            ->whereDate('position_date', '<=', $monthEnd)
            ->max('position_date');

        if (!$lastDate) {
            return ['os_end' => 0.0, 'source' => 'loan_accounts', 'position_date_used' => null];
        }

        $os = (float) LoanAccount::query()
            ->where('ao_code', $aoCode)
            ->whereDate('position_date', $lastDate)
            ->sum('outstanding');

        return [
            'os_end' => $os,
            'source' => 'loan_accounts',
            'position_date_used' => (string)$lastDate,
        ];
    }
}
