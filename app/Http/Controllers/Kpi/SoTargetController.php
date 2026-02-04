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
        abort_unless(in_array($me->level, ['SO'], true), 403);

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
        abort_unless(in_array($me->level, ['SO'], true), 403);

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
        abort_unless(in_array($me->level, ['SO'], true), 403);

        $data = $request->validate([
            'period' => ['required','date'],
            'target_os_disbursement' => ['required','integer','min:0'],
            'target_noa_disbursement' => ['required','integer','min:0'],
            'target_rr' => ['nullable','numeric','min:0','max:100'],
            'target_activity' => ['nullable','integer','min:0'],
        ]);

        $period = Carbon::parse($data['period'])->startOfMonth()->toDateString();

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
        abort_unless((int)$target->user_id === (int)$me->id, 403);
        abort_unless($target->status === KpiSoTarget::STATUS_DRAFT, 422);

        return view('kpi.so.targets.form', ['mode'=>'edit','target'=>$target]);
    }

    public function update(Request $request, KpiSoTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);
        abort_unless((int)$target->user_id === (int)$me->id, 403);
        abort_unless($target->status === KpiSoTarget::STATUS_DRAFT, 422);

        $data = $request->validate([
            'target_os_disbursement' => ['required','integer','min:0'],
            'target_noa_disbursement' => ['required','integer','min:0'],
            'target_rr' => ['nullable','numeric','min:0','max:100'],
            'target_activity' => ['nullable','integer','min:0'],
        ]);

        $target->update([
            'target_os_disbursement' => (int)$data['target_os_disbursement'],
            'target_noa_disbursement' => (int)$data['target_noa_disbursement'],
            'target_rr' => (float)($data['target_rr'] ?? $target->target_rr ?? 100),
            'target_activity' => (int)($data['target_activity'] ?? $target->target_activity ?? 0),
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

            // pakai fungsi needsTlApprovalForUser versi SO (boleh copy dari yg marketing)
            $oa = OrgAssignment::query()->active()->where('user_id', $me->id)->first();
            $next = KpiSoTarget::STATUS_PENDING_TL;

            if ($oa) {
                $leaderRole = strtoupper((string)$oa->leader_role);
                if (!in_array($leaderRole, ['TL','TLL','TLR','TLF'], true)) {
                    $next = KpiSoTarget::STATUS_PENDING_KASI;
                }
            }

            $t->status = $next;
            $t->save();
        });

        return back()->with('status', 'Target SO disubmit.');
    }
}
