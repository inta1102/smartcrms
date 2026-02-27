<?php

namespace App\Services\Kpi;

use App\Models\KpiRoTopupAdjBatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RoTopupAdjustmentService
{
    /**
     * Realtime cutoff data:
     * - prefer kpi_os_daily_aos.position_date (lebih "KPI-ish")
     * - fallback loan_accounts.position_date
     * - fallback now()
     */
    public function latestAsOfDate(): string
    {
        $d = DB::table('kpi_os_daily_aos')->max('position_date');
        if ($d) return Carbon::parse($d)->toDateString();

        $d2 = DB::table('loan_accounts')->max('position_date');
        if ($d2) return Carbon::parse($d2)->toDateString();

        return now()->toDateString();
    }

    /**
     * Hitung Net TopUp per CIF (rule existing CIF):
     * net_tu = IF(os_awal>0, max(sum_disb - os_awal, 0), 0)
     *
     * sum_disb: disb_date dalam [period_start, as_of_date]
     * os_awal : snapshot prev month (YYYY-MM-01)
     */
    public function computeNetTuForCif(string $periodMonthYmd, string $cif, string $asOfDate): array
    {
        $periodMonth = Carbon::parse($periodMonthYmd)->startOfMonth();
        $periodStart = $periodMonth->toDateString(); // YYYY-MM-01
        $snapPrev    = $periodMonth->copy()->subMonth()->startOfMonth()->toDateString();

        $cif = trim((string)$cif);

        $osAwal = (float) DB::table('loan_account_snapshots_monthly')
            ->whereDate('snapshot_month', $snapPrev)
            ->where('cif', $cif)
            ->sum(DB::raw('COALESCE(outstanding,0)'));

        $sumDisb = (float) DB::table('loan_disbursements')
            ->where('cif', $cif)
            ->whereBetween('disb_date', [$periodStart, $asOfDate])
            ->sum(DB::raw('COALESCE(amount,0)'));

        $net = 0.0;
        if ($osAwal > 0) {
            $net = max($sumDisb - $osAwal, 0);
        }

        return [
            'period_month' => $periodStart,
            'period_start' => $periodStart,
            'snap_prev'    => $snapPrev,
            'cif'          => $cif,
            'os_awal'      => $osAwal,
            'sum_disb'     => $sumDisb,
            'net_tu'       => (float) $net,
            'as_of_date'   => Carbon::parse($asOfDate)->toDateString(),
            'formula'      => 'IF(os_awal>0, GREATEST(sum_disb - os_awal,0), 0)',
            'formula_ver'  => 'v1',
        ];
    }

    /**
     * Tentukan AO asal (source) untuk CIF pada bulan KPI:
     * pilih ao_code dengan total disbursement terbesar s/d as_of_date.
     */
    public function pickSourceAoForCif(string $periodMonthYmd, string $cif, string $asOfDate): ?string
    {
        $periodStart = Carbon::parse($periodMonthYmd)->startOfMonth()->toDateString();
        $cif = trim((string)$cif);

        $row = DB::table('loan_disbursements')
            ->selectRaw("LPAD(TRIM(ao_code),6,'0') as ao_code, SUM(COALESCE(amount,0)) as total")
            ->where('cif', $cif)
            ->whereNotNull('ao_code')->whereRaw("TRIM(ao_code) <> ''")
            ->whereBetween('disb_date', [$periodStart, $asOfDate])
            ->groupBy('ao_code')
            ->orderByDesc('total')
            ->first();

        return $row?->ao_code ? $this->padAo($row->ao_code) : null;
    }

    /**
     * Freeze semua line dalam batch (dipanggil saat approve KBL).
     * - hitung net TU CIF per line (as_of_date cutoff)
     * - set source_ao_code (computed)
     * - set amount_frozen (computed)
     * - simpan calc_meta untuk audit
     */
    public function freezeBatch(int $batchId, int $approvedByUserId, ?string $asOfDate = null): void
    {
        $asOf = $asOfDate ? Carbon::parse($asOfDate)->toDateString() : $this->latestAsOfDate();

        DB::transaction(function () use ($batchId, $approvedByUserId, $asOf) {
            /** @var KpiRoTopupAdjBatch $batch */
            $batch = KpiRoTopupAdjBatch::query()
                ->whereKey($batchId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($batch->status !== 'draft') {
                abort(422, 'Batch sudah bukan draft.');
            }

            $lines = $batch->lines()->lockForUpdate()->get();

            foreach ($lines as $line) {
                $meta = $this->computeNetTuForCif($line->period_month->toDateString(), $line->cif, $asOf);
                $sourceAo = $this->pickSourceAoForCif($line->period_month->toDateString(), $line->cif, $asOf);

                $line->update([
                    'source_ao_code'  => $sourceAo,
                    'amount_frozen'   => (float)($meta['net_tu'] ?? 0),
                    'calc_as_of_date' => $asOf,
                    'calc_meta'       => $meta,
                ]);
            }

            $batch->update([
                'status' => 'approved',
                'approved_by' => $approvedByUserId,
                'approved_at' => now(),
                'approved_as_of_date' => $asOf,
            ]);
        });
    }

    /**
     * Kandidat CIF untuk adjustment (preview realtime, tanpa simpan amount).
     * Dipakai untuk UI list (search/filter).
     *
     * NOTE: ini tetap menghitung rule existing CIF (os_awal>0).
     */
    public function listCandidates(string $periodMonthYmd, ?string $search = null, int $limit = 200): array
    {
        $asOf = $this->latestAsOfDate();

        $periodMonth = Carbon::parse($periodMonthYmd)->startOfMonth();
        $periodStart = $periodMonth->toDateString();
        $snapPrev    = $periodMonth->copy()->subMonth()->startOfMonth()->toDateString();

        // disbursement per CIF (bulan ini s/d as_of)
        $disbQ = DB::table('loan_disbursements')
            ->whereBetween('disb_date', [$periodStart, $asOf])
            ->whereNotNull('cif')->whereRaw("TRIM(cif) <> ''");

        if ($search) {
            $s = trim((string)$search);
            $disbQ->where('cif', 'like', "%{$s}%");
        }

        $perCif = $disbQ
            ->selectRaw("
                cif,
                SUM(COALESCE(amount,0)) as sum_disb
            ")
            ->groupBy('cif')
            ->orderByDesc('sum_disb')
            ->limit($limit)
            ->get();

        if ($perCif->isEmpty()) {
            return [
                'as_of_date' => $asOf,
                'period_month' => $periodStart,
                'items' => [],
            ];
        }

        $cifs = $perCif->pluck('cif')->map(fn($v) => trim((string)$v))->all();

        // os_awal snapshot prev per CIF
        $osAwalMap = DB::table('loan_account_snapshots_monthly')
            ->whereDate('snapshot_month', $snapPrev)
            ->whereIn('cif', $cifs)
            ->selectRaw("cif, SUM(COALESCE(outstanding,0)) as os_awal")
            ->groupBy('cif')
            ->get()
            ->keyBy('cif');

        // source AO per CIF (AO terbesar)
        $sourceAoRows = DB::table('loan_disbursements')
            ->whereBetween('disb_date', [$periodStart, $asOf])
            ->whereIn('cif', $cifs)
            ->whereNotNull('ao_code')->whereRaw("TRIM(ao_code) <> ''")
            ->selectRaw("
                cif,
                LPAD(TRIM(ao_code),6,'0') as ao_code,
                SUM(COALESCE(amount,0)) as total
            ")
            ->groupBy('cif','ao_code')
            ->orderBy('cif')
            ->orderByDesc('total')
            ->get();

        $sourceAoMap = [];
        foreach ($sourceAoRows as $r) {
            $cif = trim((string)$r->cif);
            if (!isset($sourceAoMap[$cif])) {
                $sourceAoMap[$cif] = $this->padAo($r->ao_code);
            }
        }

        $items = [];
        foreach ($perCif as $row) {
            $cif = trim((string)$row->cif);
            $sumDisb = (float)($row->sum_disb ?? 0);

            $osAwal = (float)($osAwalMap[$cif]->os_awal ?? 0);
            $net = 0.0;
            if ($osAwal > 0) $net = max($sumDisb - $osAwal, 0);

            $items[] = [
                'cif' => $cif,
                'source_ao_code' => $sourceAoMap[$cif] ?? null,
                'os_awal' => $osAwal,
                'sum_disb' => $sumDisb,
                'net_tu' => $net,
            ];
        }

        return [
            'as_of_date' => $asOf,
            'period_month' => $periodStart,
            'snapshot_prev' => $snapPrev,
            'items' => $items,
        ];
    }

    public function padAo(?string $ao): string
    {
        $ao = trim((string)($ao ?? ''));
        return str_pad($ao, 6, '0', STR_PAD_LEFT);
    }
}