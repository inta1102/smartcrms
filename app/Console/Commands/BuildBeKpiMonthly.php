<?php

namespace App\Console\Commands;

use App\Models\KpiBeMonthly;
use App\Models\KpiBeTarget;
use App\Services\Kpi\BeKpiMonthlyService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BuildBeKpiMonthly extends Command
{
    protected $signature = 'kpi:be-build-monthly
        {--period= : Format YYYY-MM (default bulan ini)}
        {--source=auto : auto|recalc (default auto)}
        {--only= : BE user_id tunggal (opsional)}
    ';

    protected $description = 'Build/refresh KPI BE monthlies dari snapshot bulanan + installment';

    public function handle(BeKpiMonthlyService $svc): int
    {
        $periodYm = (string)($this->option('period') ?: now()->format('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $periodYm)) {
            $this->error("Format period harus YYYY-MM, contoh 2026-02");
            return self::FAILURE;
        }

        $source = strtolower((string)($this->option('source') ?: 'auto'));
        if (!in_array($source, ['auto','recalc'], true)) $source = 'auto';

        $onlyId = $this->option('only');
        $onlyId = $onlyId !== null ? (int)$onlyId : null;

        $periodStart = Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth();
        $periodDate  = $periodStart->toDateString(); // YYYY-MM-01

        // Ambil BE users (sesuaikan bila BE pakai level lain)
        $usersQ = DB::table('users')
            ->select(['id','name','ao_code','level'])
            ->where('level', 'BE')
            ->whereNotNull('ao_code')
            ->where('ao_code','!=','');

        if ($onlyId) $usersQ->where('id', $onlyId);

        $users = $usersQ->get();

        if ($users->isEmpty()) {
            $this->warn('Tidak ada user BE yang memenuhi syarat (level=BE dan punya ao_code).');
            return self::SUCCESS;
        }

        // Targets keyBy be_user_id (optional)
        $targets = KpiBeTarget::query()
            ->where('period', $periodDate)
            ->whereIn('be_user_id', $users->pluck('id')->all())
            ->get()
            ->keyBy('be_user_id');

        // Hitung realtime batch (menggunakan service yang sudah include lunas)
        // Kita pakai method internal calculateRealtime via trick:
        // -> buildDashboard akan campur monthly+realtime; kita butuh pure realtime,
        // jadi kita panggil calculateOne per user untuk aman.
        $count = 0;

        DB::transaction(function () use ($svc, $users, $periodYm, $periodDate, $source, &$count) {
            foreach ($users as $u) {
                $row = $svc->calculateOneForSubmit($periodYm, (int)$u->id);

                $payload = [
                    'actual_os_selesai' => (float)$row['actual']['os'],
                    'actual_noa_selesai' => (int)$row['actual']['noa'],
                    'actual_bunga_masuk' => (float)$row['actual']['bunga'],
                    'actual_denda_masuk' => (float)$row['actual']['denda'],

                    'score_os' => (int)$row['score']['os'],
                    'score_noa' => (int)$row['score']['noa'],
                    'score_bunga' => (int)$row['score']['bunga'],
                    'score_denda' => (int)$row['score']['denda'],

                    'pi_os' => (float)$row['pi']['os'],
                    'pi_noa' => (float)$row['pi']['noa'],
                    'pi_bunga' => (float)$row['pi']['bunga'],
                    'pi_denda' => (float)$row['pi']['denda'],
                    'total_pi' => (float)$row['pi']['total'],

                    'os_npl_prev' => (float)$row['actual']['os_npl_prev'],
                    'os_npl_now' => (float)$row['actual']['os_npl_now'],
                    'net_npl_drop' => (float)$row['actual']['net_npl_drop'],

                    // jejak sumber update
                    'status' => $source, // auto / recalc
                ];

                KpiBeMonthly::query()->updateOrCreate(
                    ['period' => $periodDate, 'be_user_id' => (int)$u->id],
                    $payload
                );

                $count++;
            }
        });

        $this->info("OK: build KPI BE monthlies {$count} user untuk period {$periodYm} (status={$source}).");
        return self::SUCCESS;
    }
}
