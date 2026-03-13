<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DekonWaterfallBuilderService
{
    public function build(string|Carbon $periodYm, string $mode = 'eom'): array
    {
        $period = $periodYm instanceof Carbon
            ? $periodYm->copy()->startOfMonth()
            : Carbon::parse($periodYm)->startOfMonth();

        $mode = strtolower(trim((string) $mode));
        if (!in_array($mode, ['eom', 'realtime', 'hybrid'], true)) {
            $mode = 'eom';
        }

        $prevPeriod = $period->copy()->subMonthNoOverflow()->startOfMonth();

        // =============================
        // OS bulan lalu (selalu snapshot)
        // =============================
        $osPrev = $this->getSnapshotOutstanding($prevPeriod);

        // =============================
        // OS bulan ini / current
        // =============================
        $osCurrent = $this->useLiveSource($period, $mode)
            ? $this->getLiveOutstanding()
            : $this->getSnapshotOutstanding($period);

        // =============================
        // CREDIT ACTIVITY dari movement
        // =============================
        $movement = DB::table('dashboard_dekom_movements')
            ->whereDate('period_month', $period->toDateString())
            ->where('mode', $mode)
            ->where('section', 'credit_activity')
            ->get()
            ->keyBy(fn ($r) => ($r->subgroup ?? 'summary') . '.' . $r->line_key);

        $newCif         = (float) ($movement->get('pembukaan.new_cif')->plafond_baru ?? 0);
        $nasabahLama    = (float) ($movement->get('pembukaan.nasabah_lama')->plafond_baru ?? 0);
        $kreditLagi     = (float) ($movement->get('pelunasan.kredit_lagi')->plafond_baru ?? 0);
        $tutupFasilitas = (float) ($movement->get('pelunasan.tutup_fasilitas')->os_amount ?? 0);

        $disbursement = $newCif + $nasabahLama + $kreditLagi;

        return [
            'period'       => $period->format('Y-m'),
            'mode'         => $mode,
            'os_prev'      => $osPrev,
            'disbursement' => $disbursement,
            'pelunasan'    => $tutupFasilitas,
            'os_current'   => $osCurrent,
            'delta'        => $osCurrent - $osPrev,
            'recon_delta'  => $disbursement - $tutupFasilitas,
            'is_live'      => $this->useLiveSource($period, $mode),
        ];
    }

    protected function getSnapshotOutstanding(Carbon $period): float
    {
        if (!Schema::hasTable('loan_account_snapshots_monthly')) {
            return 0.0;
        }

        return (float) DB::table('loan_account_snapshots_monthly')
            ->whereYear('snapshot_month', $period->year)
            ->whereMonth('snapshot_month', $period->month)
            ->sum('outstanding');
    }

    protected function getLiveOutstanding(): float
    {
        if (!Schema::hasTable('loan_accounts')) {
            return 0.0;
        }

        $latestPositionDate = DB::table('loan_accounts')->max('position_date');

        if (!$latestPositionDate) {
            return 0.0;
        }

        return (float) DB::table('loan_accounts')
            ->whereDate('position_date', $latestPositionDate)
            ->sum('outstanding');
    }

    protected function useLiveSource(Carbon $period, string $mode): bool
    {
        if ($mode === 'eom') {
            return false;
        }

        return $period->copy()->startOfMonth()->equalTo(now()->copy()->startOfMonth());
    }
}