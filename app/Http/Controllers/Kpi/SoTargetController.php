<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\KpiSoTarget;
use App\Models\OrgAssignment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SoTargetController extends Controller
{
    public function index()
    {
        $me = auth()->user();
        abort_unless($me, 403);
        $this->ensureSo($me);

        $targets = KpiSoTarget::query()
            ->where('user_id', $me->id)
            ->orderByDesc('period')
            ->paginate(10);

        return view('kpi.so.targets.index', compact('targets'));
    }

    public function create(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);
        $this->ensureSo($me);
        
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
        $this->ensureSo($me);

        $data = $request->validate([
            'period' => ['required','date_format:Y-m'],
            'target_os_disbursement' => ['required','integer','min:0'],
            'target_noa_disbursement' => ['required','integer','min:0'],
            'target_rr' => ['nullable','numeric','min:0','max:100'],
            'target_activity' => ['nullable','integer','min:0'],
        ]);

        $period = Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth()->toDateString();

        $target = KpiSoTarget::query()->updateOrCreate(
            ['period' => $period, 'user_id' => $me->id],
            [
                'ao_code' => (string)($me->ao_code ?? null),
                'target_os_disbursement' => (int)$data['target_os_disbursement'],
                'target_noa_disbursement' => (int)$data['target_noa_disbursement'],
                'target_rr' => (float)($data['target_rr'] ?? 100),
                'target_activity' => (int)($data['target_activity'] ?? 0),
                'status' => KpiSoTarget::STATUS_DRAFT,
            ]
        );

        return redirect()->route('kpi.so.targets.edit', $target)->with('status', 'Draft target SO tersimpan.');
    }

    public function edit(KpiSoTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);
        $this->ensureSo($me);

        abort_unless((int)$target->user_id === (int)$me->id, 403);
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
        $this->ensureSo($me);

        abort_unless((int)$target->user_id === (int)$me->id, 403);
        abort_unless($target->status === KpiSoTarget::STATUS_DRAFT, 422);

        $data = $request->validate([
            // period TIDAK perlu divalidasi di update kalau inputnya disabled
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
        abort_unless((int)$target->user_id === (int)$me->id, 403);
        abort_unless($target->status === KpiSoTarget::STATUS_DRAFT, 422);

        DB::transaction(function () use ($me, $target) {
            $t = KpiSoTarget::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            abort_unless($t->status === KpiSoTarget::STATUS_DRAFT, 422);

            $needsTl = $this->needsTlApprovalForUser((int)$me->id);

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

        // kalau struktur tidak ada → langsung ke KASI (skip TL)
        if (!$oa) return false;

        $leaderRole = strtoupper(trim((string)$oa->leader_role));
        return in_array($leaderRole, ['TL','TLL','TLR','TLF'], true);
    }


    private function ensureSo($me): void
    {
        $level = strtoupper(trim((string)($me->level instanceof \BackedEnum ? $me->level->value : $me->level)));
        $roleValue = method_exists($me, 'roleValue') ? strtoupper(trim((string)$me->roleValue())) : '';

        abort_unless(
            ($me && (
                $me->hasAnyRole(['SO']) ||
                $roleValue === 'SO' ||
                $level === 'SO'
            )),
            403
        );
    }


}
