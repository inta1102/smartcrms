<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\KpiSoTarget;
use App\Models\OrgAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SoTargetController extends Controller
{
    public function index(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);
        $this->ensureKbl($me);

        $periodYm = $request->query('period', now()->format('Y-m'));
        $period   = Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth()->toDateString();

        // semua user SO (yang punya ao_code) -> buat list seperti komunitas input
        $users = DB::table('users')
            ->select(['id','name','ao_code','level'])
            ->whereNotNull('ao_code')
            ->where('ao_code', '!=', '')
            ->whereIn('level', ['SO'])
            ->orderBy('name')
            ->get();

        // target per user untuk period
        $targets = KpiSoTarget::query()
            ->where('period', $period)
            ->get()
            ->keyBy('user_id');

        // view index kamu bisa dibuat seperti tabel input komunitas:
        // tiap baris user SO + input target
        return view('kpi.so.targets.index', [
            'periodYm' => $periodYm,
            'period'   => Carbon::parse($period),
            'users'    => $users,
            'targets'  => $targets,
        ]);
    }

    public function create(Request $request)
    {
        // kalau kamu sudah pakai index sebagai bulk input,
        // create per-user sebenarnya tidak diperlukan.
        // Tapi kalau masih mau keep, tetap KBL-only.
        $me = auth()->user();
        abort_unless($me, 403);
        $this->ensureKbl($me);

        $period = $request->get('period')
            ? Carbon::parse($request->get('period'))->startOfMonth()->toDateString()
            : now()->startOfMonth()->toDateString();

        $target = new KpiSoTarget([
            'period' => $period,
            'target_rr' => 100,
            'target_activity' => 0,
        ]);

        return view('kpi.so.targets.form', [
            'mode' => 'create',
            'target' => $target,
        ]);
    }

    public function store(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);
        $this->ensureKbl($me);

        /**
         * Bulk save: kirim array targets[user_id][field]
         * Sama gaya komunitas input.
         */
        $data = $request->validate([
            'period' => ['required','date_format:Y-m'],
            'targets' => ['required','array'],
            'targets.*.target_os_disbursement'  => ['required','integer','min:0'],
            'targets.*.target_noa_disbursement' => ['required','integer','min:0'],
            'targets.*.target_rr'               => ['nullable','numeric','min:0','max:100'],
            'targets.*.target_activity'         => ['nullable','integer','min:0'],
        ]);

        $period = Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth()->toDateString();

        // ambil map ao_code SO supaya target tersimpan konsisten
        $soUsers = DB::table('users')
            ->select(['id','ao_code','level'])
            ->whereIn('id', array_map('intval', array_keys($data['targets'])))
            ->get()
            ->keyBy('id');

        DB::transaction(function () use ($period, $data, $soUsers) {
            foreach ($data['targets'] as $userId => $row) {
                $userId = (int)$userId;

                $u = $soUsers->get($userId);
                // pastikan hanya untuk SO
                if (!$u || strtoupper((string)$u->level) !== 'SO') continue;

                $aoCode = (string)($u->ao_code ?? null);

                KpiSoTarget::query()->updateOrCreate(
                    ['period' => $period, 'user_id' => $userId],
                    [
                        'ao_code' => $aoCode ?: null,
                        'target_os_disbursement'  => (int)$row['target_os_disbursement'],
                        'target_noa_disbursement' => (int)$row['target_noa_disbursement'],
                        'target_rr'               => (float)($row['target_rr'] ?? 100),
                        'target_activity'         => (int)($row['target_activity'] ?? 0),
                        'status'                  => KpiSoTarget::STATUS_DRAFT,
                    ]
                );
            }
        });

        return back()->with('status', 'Draft target SO (bulk) tersimpan.');
    }

    public function edit(KpiSoTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);
        $this->ensureKbl($me);

        // KBL boleh edit siapapun, tapi hanya saat draft
        abort_unless($target->status === KpiSoTarget::STATUS_DRAFT, 422);

        return view('kpi.so.targets.form', [
            'mode'   => 'edit',
            'target' => $target,
        ]);
    }

    public function update(Request $request, KpiSoTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);
        $this->ensureKbl($me);

        abort_unless($target->status === KpiSoTarget::STATUS_DRAFT, 422);

        $data = $request->validate([
            'target_os_disbursement'  => ['required','integer','min:0'],
            'target_noa_disbursement' => ['required','integer','min:0'],
            'target_rr'               => ['nullable','numeric','min:0','max:100'],
            'target_activity'         => ['nullable','integer','min:0'],
        ]);

        $target->update([
            'target_os_disbursement'  => (int)$data['target_os_disbursement'],
            'target_noa_disbursement' => (int)$data['target_noa_disbursement'],
            'target_rr'               => (float)($data['target_rr'] ?? $target->target_rr ?? 100),
            'target_activity'         => (int)($data['target_activity'] ?? $target->target_activity ?? 0),
        ]);

        return back()->with('status', 'Draft target SO diperbarui.');
    }

    public function submit(KpiSoTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);
        $this->ensureKbl($me);

        abort_unless($target->status === KpiSoTarget::STATUS_DRAFT, 422);

        DB::transaction(function () use ($target) {
            $t = KpiSoTarget::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            abort_unless($t->status === KpiSoTarget::STATUS_DRAFT, 422);

            // approval based on struktur SO pemilik target
            $needsTl = $this->needsTlApprovalForUser((int)$t->user_id);

            $t->status = $needsTl
                ? KpiSoTarget::STATUS_PENDING_TL
                : KpiSoTarget::STATUS_PENDING_KASI;

            $t->save();
        });

        return back()->with('status', 'Target SO disubmit.');
    }

    protected function needsTlApprovalForUser(int $userId): bool
    {
        $oa = OrgAssignment::query()
            ->active()
            ->where('user_id', $userId)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if (!$oa) return false;

        $leaderRole = strtoupper(trim((string)$oa->leader_role));
        return in_array($leaderRole, ['TL','TLL','TLR','TLF'], true);
    }

    private function ensureKbl($me): void
    {
        $roleValue = method_exists($me, 'roleValue') ? strtoupper(trim((string)$me->roleValue())) : '';
        $level     = strtoupper(trim((string)($me->level instanceof \BackedEnum ? $me->level->value : $me->level)));

        abort_unless(
            $me && (
                $me->hasAnyRole(['KBL']) ||
                $roleValue === 'KBL' ||
                $level === 'KBL'
            ),
            403
        );
    }
}
