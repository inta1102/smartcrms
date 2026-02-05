<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kpi\StoreMarketingTargetRequest;
use App\Http\Requests\Kpi\UpdateMarketingTargetRequest;
use App\Models\LoanAccount;
use App\Models\MarketingKpiTarget;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\OrgAssignment;

class MarketingTargetController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        if (strtoupper((string)$user->level) === 'SO') {
            return redirect()->route('kpi.so.targets.index');
        }

        $targets = MarketingKpiTarget::query()
            ->with(['achievement'])
            ->where('user_id', $user->id)
            ->orderByDesc('period')
            ->paginate(10)
            ->withQueryString();

        return view('kpi.marketing.targets.index', compact('targets'));
    }

    public function create(Request $request)
    {
        $user = auth()->user();
        abort_unless($user, 403);
        abort_unless($user->hasAnyRole(['AO','RO','FE','BE']), 403);

        $period = $request->get('period')
            ? Carbon::parse($request->get('period'))->startOfMonth()
            : now()->startOfMonth();

        $helper = $this->buildHelper($user);

        return view('kpi.marketing.targets.form', [
            'mode'   => 'create',
            'target' => new MarketingKpiTarget([
                'period'          => $period->toDateString(),

                // ✅ default saran bobot sesuai KPI SO Sheet
                'weight_os'       => 55,
                'weight_noa'      => 15,
                'weight_rr'       => 20,
                'weight_activity' => 10,

                // ✅ default target
                'target_rr'       => 100,
                'target_activity' => 0,
            ]),
            'helper' => $helper,
        ]);
    }

    public function store(StoreMarketingTargetRequest $request)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $period = Carbon::parse($request->period)->startOfMonth()->toDateString();

        // ✅ guardrail bobot = 100
        // $wOs  = (int)($request->weight_os ?? 55);
        // $wNoa = (int)($request->weight_noa ?? 15);
        // $wRr  = (int)($request->weight_rr ?? 20);
        // $wAct = (int)($request->weight_activity ?? 10);

        $wOs=55; $wNoa=15; $wRr=20; $wAct=10;
        abort_unless(($wOs + $wNoa + $wRr + $wAct) === 100, 422);

        $target = MarketingKpiTarget::query()->updateOrCreate(
            [
                'period'  => $period,
                'user_id' => $user->id,
            ],
            [
                'branch_code'      => $request->branch_code ?: null,

                'target_os_growth' => (int)$request->target_os_growth,
                'target_noa'       => (int)$request->target_noa,

                // ✅ NEW
                'target_rr'        => (float)($request->target_rr ?? 100),
                'target_activity' => (int)($request->input('target_activity', 0)),

                // ✅ weights 4 komponen
                'weight_os'        => $wOs,
                'weight_noa'       => $wNoa,
                'weight_rr'        => $wRr,
                'weight_activity'  => $wAct,

                'notes'            => $request->notes,
                'status'           => MarketingKpiTarget::STATUS_DRAFT,
                'proposed_by'      => $user->id,
                'is_locked'        => false,
            ]
        );

        return redirect()
            ->route('kpi.marketing.targets.edit', $target)
            ->with('status', 'Draft target KPI berhasil disimpan. Silakan Submit jika sudah final.');
    }

    public function edit(MarketingKpiTarget $target)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        abort_unless((int)$target->user_id === (int)$user->id, 403);

        if ($target->is_locked || $target->status !== MarketingKpiTarget::STATUS_DRAFT) {
            return redirect()
                ->route('kpi.marketing.targets.index')
                ->with('status', 'Target sudah disubmit / diproses, tidak bisa diedit.');
        }

        $helper = $this->buildHelper($user);

        return view('kpi.marketing.targets.form', [
            'mode'   => 'edit',
            'target' => $target,
            'helper' => $helper,
        ]);
    }

    public function update(UpdateMarketingTargetRequest $request, MarketingKpiTarget $target)
    {
        $user = auth()->user();
        abort_unless($user, 403);
        abort_unless((int)$target->user_id === (int)$user->id, 403);

        abort_unless(!$target->is_locked, 422);
        abort_unless($target->status === MarketingKpiTarget::STATUS_DRAFT, 422);

        // ✅ guardrail bobot = 100
        // $wOs  = (int)($request->weight_os ?? $target->weight_os ?? 55);
        // $wNoa = (int)($request->weight_noa ?? $target->weight_noa ?? 15);
        // $wRr  = (int)($request->weight_rr ?? $target->weight_rr ?? 20);
        // $wAct = (int)($request->weight_activity ?? $target->weight_activity ?? 10);

        $wOs=55; $wNoa=15; $wRr=20; $wAct=10;
        abort_unless(($wOs + $wNoa + $wRr + $wAct) === 100, 422);

        $target->update([
            'branch_code'      => $request->branch_code ?: null,

            'target_os_growth' => (int)$request->target_os_growth,
            'target_noa'       => (int)$request->target_noa,

            'target_rr' => (float)($request->input('target_rr', $target->target_rr ?? 100)),

            // ✅ aman: tidak reset kalau field tidak terkirim
            'target_activity' => (int)($request->input('target_activity', $target->target_activity ?? 0)),

            'weight_os'        => 55,
            'weight_noa'       => 15,
            'weight_rr'        => 20,
            'weight_activity'  => 10,

            'notes'            => $request->notes,
        ]);


        return back()->with('status', 'Draft target KPI diperbarui.');
    }

    public function submit(MarketingKpiTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        \Log::info('MARKETING KPI SUBMIT HIT', [
            'target_id' => $target->id,
            'user_id' => $me->id,
            'status_before' => $target->status,
        ]);

        // cuma target milik sendiri
        abort_unless((int)$target->user_id === (int)$me->id, 403);

        // hanya bisa submit dari draft
        abort_unless($target->status === MarketingKpiTarget::STATUS_DRAFT, 422);
        abort_unless(!$target->is_locked, 422);

        DB::transaction(function () use ($me, $target) {
            $t = MarketingKpiTarget::query()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($t->status === MarketingKpiTarget::STATUS_DRAFT, 422);

            // ✅ satu sumber kebenaran
            $needsTl = $this->needsTlApprovalForUser((int)$me->id);

            $next = $needsTl
                ? MarketingKpiTarget::STATUS_PENDING_TL
                : MarketingKpiTarget::STATUS_PENDING_KASI;

            $t->status = $next;
            $t->proposed_by = $me->id;
            $t->save();

            \Log::info('MARKETING KPI SUBMIT ROUTE', [
                'target_id' => $t->id,
                'user_id' => $me->id,
                'needs_tl' => $needsTl,
                'status_after' => $next,
            ]);
        });

        return back()->with('status', 'Target berhasil disubmit & masuk inbox approval.');
    }

    protected function buildHelper($user): array
    {
        $aoCode = (string)($user->ao_code ?? '');

        if ($aoCode === '') {
            return [
                'ao_code' => null,
                'position_date' => null,
                'os_current' => null,
                'noa_current' => null,
            ];
        }

        $lastDate = LoanAccount::query()
            ->where('ao_code', $aoCode)
            ->max('position_date');

        if (!$lastDate) {
            return [
                'ao_code' => $aoCode,
                'position_date' => null,
                'os_current' => 0,
                'noa_current' => 0,
            ];
        }

        $os = (float) LoanAccount::query()
            ->where('ao_code', $aoCode)
            ->whereDate('position_date', $lastDate)
            ->sum('outstanding');

        $noa = (int) LoanAccount::query()
            ->where('ao_code', $aoCode)
            ->whereDate('position_date', $lastDate)
            ->count();

        return [
            'ao_code' => $aoCode,
            'position_date' => (string)$lastDate,
            'os_current' => $os,
            'noa_current' => $noa,
        ];
    }

    protected function needsTlApprovalForUser(int $userId): bool
    {
        $oa = OrgAssignment::query()
            ->active()
            ->where('user_id', $userId)
            ->first();

        // ✅ kalau tidak ada TL/struktur tidak lengkap, SKIP TL (langsung ke KASI)
        if (!$oa) return false;

        $leaderRole = strtoupper(trim((string) $oa->leader_role));

        // ✅ butuh TL hanya jika leader_role memang TL*
        return in_array($leaderRole, ['TL','TLL','TLR','TLF'], true);
    }

}
