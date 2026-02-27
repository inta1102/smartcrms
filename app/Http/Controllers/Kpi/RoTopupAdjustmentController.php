<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\KpiRoTopupAdjBatch;
use App\Models\KpiRoTopupAdjLine;
use App\Models\User;
use App\Services\Kpi\RoTopupAdjustmentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RoTopupAdjustmentController extends Controller
{
    public function __construct(
        protected RoTopupAdjustmentService $svc
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('kpi-ro-topup-adj-view');

        // period: support 2026-02 / 2026-02-01
        $raw = trim((string)$request->query('period', ''));
        if ($raw === '') {
            $period = now()->startOfMonth();
        } elseif (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            $period = Carbon::createFromFormat('Y-m', $raw)->startOfMonth();
        } else {
            $period = Carbon::parse($raw)->startOfMonth();
        }
        $periodMonth = $period->toDateString();

        $searchCif = trim((string)$request->query('cif', ''));

        // kandidat CIF (preview realtime)
        $candidates = $this->svc->listCandidates($periodMonth, $searchCif ?: null, 200);
        
        $items = $candidates['items'] ?? [];

        // CIF kandidat: simpan versi asli & versi key (tanpa nol)
        $cifRows = collect($items)
            ->map(function ($r) {
                $cif = trim((string)($r['cif'] ?? ''));
                if ($cif === '') return null;

                return [
                    'cif'     => $cif,                  // asli (mis: 0000012047)
                    'cif_key' => ltrim($cif, '0'),      // key (mis: 12047)
                ];
            })
            ->filter()
            ->unique('cif')
            ->values();

        $cifKeys = $cifRows->pluck('cif_key')->filter()->unique()->values();

        // ambil nama dari loan_accounts pakai cif_key (tanpa nol)
        $namesByCifKey = DB::table('loan_accounts')
            ->whereIn(DB::raw("TRIM(LEADING '0' FROM cif)"), $cifKeys)
            ->selectRaw("
                TRIM(LEADING '0' FROM cif) as cif_key,
                MAX(customer_name) as customer_name
            ")
            ->groupBy('cif_key')
            ->pluck('customer_name', 'cif_key');

        // gabungkan jadi options final
        $cifOptions = $cifRows->map(function ($row) use ($namesByCifKey) {
            $key = (string)($row['cif_key'] ?? '');
            return [
                'cif'           => $row['cif'],
                'customer_name' => $namesByCifKey[$key] ?? null,
            ];
        })->values()->all();

        logger()->info('[TOPUP-ADJ] cifOptions sample', ['sample' => $cifOptions[0] ?? null]);
        $found = collect($cifOptions)->filter(fn($x) => !empty($x['customer_name']))->count();
        logger()->info('[TOPUP-ADJ] cifOptions name found', ['found' => $found, 'total' => count($cifOptions)]);

        // list batches bulan tsb
        $batches = KpiRoTopupAdjBatch::query()
            ->whereDate('period_month', $periodMonth)
            ->orderByDesc('id')
            ->withCount('lines')
            ->get();

        // list AO RO untuk dropdown target (pakai users level RO yang punya ao_code)
        $ros = DB::table('users')
            ->whereRaw("UPPER(TRIM(level)) = 'RO'")
            ->whereNotNull('ao_code')
            ->whereRaw("TRIM(ao_code) <> ''")
            ->selectRaw("LPAD(TRIM(ao_code),6,'0') as ao_code, name")
            ->orderBy('name')
            ->get();

        $me = auth()->user();
        $role = strtoupper((string)($me?->roleValue() ?? ''));

        $canCreate = Gate::allows('kpi-ro-topup-adj-create');
        $canApprove = Gate::allows('kpi-ro-topup-adj-approve');    

        $base = Carbon::parse($periodDate ?? now())->startOfMonth(); // atau pakai $periodMonth yang sudah kamu hitung
        $periodOptions = collect(range(0, 17))
            ->map(fn($i) => $base->copy()->subMonths($i)->format('Y-m'))
            ->values()
            ->all();
        
            logger()->info('[TOPUP-ADJ] before return', [
            'cifOptions_is_set' => isset($cifOptions),
            'cifOptions_count'  => is_array($cifOptions ?? null) ? count($cifOptions) : -1,
            'view'              => 'kpi.ro.topup_adj.index',
            ]);

            logger()->info('[TOPUP-ADJ] return payload', [
            'cifOptions_count' => is_array($data['cifOptions'] ?? null) ? count($data['cifOptions']) : -1
            ]);

            logger()->info('[TOPUP-ADJ] final cifOptions for view', [
                'isset' => isset($cifOptions),
                'type'  => gettype($cifOptions ?? null),
                'count' => is_array($cifOptions ?? null) ? count($cifOptions) : -1,
            ]);
 
        return view('kpi.ro.topup_adj.index', [
            'period'        => $period,
            'periodMonth'   => $periodMonth,
            'searchCif'     => $searchCif,
            'candidates'    => $candidates,
            'batches'       => $batches,
            'ros'           => $ros,
            'role'          => $role,
            'canCreate'     => $canCreate,
            'canApprove'    => $canApprove,
            'periodOptions' => $periodOptions,
            'cifOptions'    => $cifOptions,   // <<< ini yang penting
        ]);

    }

    public function storeBatch(Request $request)
    {
        Gate::authorize('kpi-ro-topup-adj-create');

        $data = $request->validate([
            'period_month' => ['required','date'],
            'notes' => ['nullable','string','max:2000'],
        ]);

        $periodMonth = Carbon::parse($data['period_month'])->startOfMonth()->toDateString();

        $batch = KpiRoTopupAdjBatch::create([
            'period_month' => $periodMonth,
            'status' => 'draft',
            'created_by' => auth()->id(),
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()
            ->route('kpi.ro.topup_adj.batches.show', ['batch' => $batch->id, 'period' => $periodMonth])
            ->with('ok', 'Batch draft dibuat.');
    }

    public function showBatch(Request $request, KpiRoTopupAdjBatch $batch)
    {
        Gate::authorize('kpi-ro-topup-adj-view');

        $batch->load(['lines' => function ($q) {
            $q->orderBy('id');
        }]);

        // period param agar balik ke index rapi
        $periodMonth = $batch->period_month->toDateString();

        // realtime preview info (buat bantu user paham)
        $asOf = $this->svc->latestAsOfDate();

        // ✅ kandidat CIF (preview realtime) - pake period batch
        $candidates = $this->svc->listCandidates($periodMonth, null, 200);
        $items = $candidates['items'] ?? [];

        // ✅ build CIF options (CIF + customer_name)
        $cifRows = collect($items)
            ->map(function ($r) {
                $cif = trim((string)($r['cif'] ?? ''));
                if ($cif === '') return null;

                return [
                    'cif'     => $cif,
                    'cif_key' => ltrim($cif, '0'),
                ];
            })
            ->filter()
            ->unique('cif')
            ->values();

        $cifKeys = $cifRows->pluck('cif_key')->filter()->unique()->values();

        $namesByCifKey = DB::table('loan_accounts')
            ->whereIn(DB::raw("TRIM(LEADING '0' FROM cif)"), $cifKeys)
            ->selectRaw("
                TRIM(LEADING '0' FROM cif) as cif_key,
                MAX(customer_name) as customer_name
            ")
            ->groupBy('cif_key')
            ->pluck('customer_name', 'cif_key');

        $cifOptions = $cifRows->map(function ($row) use ($namesByCifKey) {
            $key = (string)($row['cif_key'] ?? '');
            return [
                'cif'           => $row['cif'],
                'customer_name' => $namesByCifKey[$key] ?? null,
            ];
        })->values()->all();

        // dropdown RO target
        $ros = DB::table('users')
            ->whereRaw("UPPER(TRIM(level)) = 'RO'")
            ->whereNotNull('ao_code')
            ->whereRaw("TRIM(ao_code) <> ''")
            ->selectRaw("LPAD(TRIM(ao_code),6,'0') as ao_code, name")
            ->orderBy('name')
            ->get();

        $canCreate = Gate::allows('kpi-ro-topup-adj-create');
        $canApprove = Gate::allows('kpi-ro-topup-adj-approve');

        // ringkas total frozen (kalau approved)
        $sumFrozen = (float)$batch->lines->sum('amount_frozen');

        return view('kpi.ro.topup_adj.batch', compact(
            'batch',
            'periodMonth',
            'asOf',
            'ros',
            'canCreate',
            'canApprove',
            'sumFrozen',
            'cifOptions'    // ✅ WAJIB ditambahkan
        ));
    }

    public function storeLine(Request $request, KpiRoTopupAdjBatch $batch)
    {
        Gate::authorize('kpi-ro-topup-adj-create');

        abort_unless($batch->status === 'draft', 422, 'Batch bukan draft.');

        $data = $request->validate([
            'cif' => ['required','string','max:32'],
            'target_ao_code' => ['required','string','max:6'],
            'reason' => ['nullable','string','max:2000'],
        ]);

        $cif = trim((string)$data['cif']);
        $targetAo = str_pad(trim((string)$data['target_ao_code']), 6, '0', STR_PAD_LEFT);

        // optional: cegah duplicate CIF+target di batch yg sama
        $exists = KpiRoTopupAdjLine::query()
            ->where('batch_id', $batch->id)
            ->where('cif', $cif)
            ->where('target_ao_code', $targetAo)
            ->exists();
        if ($exists) {
            return back()->with('err', 'Line untuk CIF + target AO ini sudah ada di batch.');
        }

        KpiRoTopupAdjLine::create([
            'batch_id' => $batch->id,
            'period_month' => $batch->period_month->toDateString(),
            'cif' => $cif,
            'target_ao_code' => $targetAo,
            'reason' => $data['reason'] ?? null,

            // amount_frozen nanti diisi saat approve (freeze)
            'amount_frozen' => 0,
        ]);

        return back()->with('ok', 'Line ditambahkan (amount akan freeze saat approve KBL).');
    }

    public function deleteLine(Request $request, KpiRoTopupAdjBatch $batch, KpiRoTopupAdjLine $line)
    {
        Gate::authorize('kpi-ro-topup-adj-create');

        abort_unless($batch->id === $line->batch_id, 404);
        abort_unless($batch->status === 'draft', 422, 'Batch bukan draft.');

        $line->delete();
        return back()->with('ok', 'Line dihapus.');
    }

    public function approveBatch(Request $request, KpiRoTopupAdjBatch $batch)
    {
        Gate::authorize('kpi-ro-topup-adj-approve'); // ✅ KBL only

        abort_unless($batch->status === 'draft', 422, 'Batch bukan draft.');

        if ($batch->lines()->count() <= 0) {
            return back()->with('err', 'Batch kosong. Tambahkan minimal 1 line.');
        }

        $this->svc->freezeBatch($batch->id, auth()->id(), null);

        return redirect()
            ->route('kpi.ro.topup_adj.batches.show', ['batch' => $batch->id])
            ->with('ok', 'Batch approved. Amount sudah dibekukan (freeze).');
    }
}