<?php

namespace App\Services\Kpi;

use App\Models\LoanAccount;
use App\Models\MarketingKpiSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MarketingKpiSnapshotService
{
    public function buildForPeriod(string $periodYmd, ?int $onlyUserId = null): array
    {
        $period = Carbon::parse($periodYmd)->startOfMonth();
        $monthEnd = $period->copy()->endOfMonth()->toDateString();

        $prevPeriod = $period->copy()->subMonthNoOverflow();
        $prevMonthEnd = $prevPeriod->endOfMonth()->toDateString();

        $usersQ = User::query()
            ->whereNotNull('ao_code')
            ->where('ao_code', '!= '');

        if ($onlyUserId) {
            $usersQ->whereKey($onlyUserId);
        }

        $users = $usersQ->get(['id', 'ao_code', 'name']);
        $upsert = 0;

        DB::transaction(function () use (
            $users, $period, $monthEnd, $prevMonthEnd, &$upsert
        ) {
            foreach ($users as $u) {
                $ao = $u->ao_code;

                // ======================
                // OS CLOSING (akhir bulan ini)
                // ======================
                $osClosing = (float) LoanAccount::query()
                    ->where('ao_code', $ao)
                    ->whereDate('position_date', $monthEnd)
                    ->sum('outstanding');

                // ======================
                // OS OPENING (akhir bulan sebelumnya)
                // ======================
                $osOpening = (float) LoanAccount::query()
                    ->where('ao_code', $ao)
                    ->whereDate('position_date', $prevMonthEnd)
                    ->sum('outstanding');

                // fallback kalau data bulan lalu belum ada
                if ($osOpening === 0.0) {
                    $prevSnap = MarketingKpiSnapshot::query()
                        ->whereDate('period', $prevPeriod->startOfMonth()->toDateString())
                        ->where('user_id', $u->id)
                        ->first();

                    $osOpening = $prevSnap ? (float) $prevSnap->os_closing : $osClosing;
                }

                $osGrowth = $osClosing - $osOpening;

                // ======================
                // NOA NEW
                // ======================
                $noaNew = LoanAccount::query()
                    ->select('account_no')
                    ->where('ao_code', $ao)
                    ->groupBy('account_no')
                    ->havingRaw('MIN(position_date) BETWEEN ? AND ?', [
                        $period->toDateString(),
                        $monthEnd,
                    ])
                    ->count();

                // total rekening aktif (opsional)
                $noaTotal = (int) LoanAccount::query()
                    ->where('ao_code', $ao)
                    ->whereDate('position_date', $monthEnd)
                    ->count();

                MarketingKpiSnapshot::updateOrCreate(
                    [
                        'period'  => $period->toDateString(),
                        'user_id' => $u->id,
                    ],
                    [
                        'os_opening' => $osOpening,
                        'os_closing' => $osClosing,
                        'os_growth'  => $osGrowth,
                        'noa_new'    => $noaNew,
                        'noa_total'  => $noaTotal,
                        'snapshot_at'=> now(),
                        'source'     => 'CBS_POSITION_DATE',
                    ]
                );

                $upsert++;
            }
        });

        return [
            'period' => $period->toDateString(),
            'users'  => $users->count(),
            'upsert' => $upsert,
        ];
    }
}
