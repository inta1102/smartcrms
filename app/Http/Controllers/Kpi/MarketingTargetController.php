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
use App\Models\MarketingKpiMonthly;


class MarketingTargetController extends Controller
{

    public function index(Request $request)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $targets = MarketingKpiTarget::query()
            ->with(['achievement']) // ✅ supaya list bisa tampil score/summary tanpa query N+1
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
        abort_unless($user->hasAnyRole(['AO','RO','SO','FE','BE']), 403); // sesuaikan role marketing

        // Default period = bulan berjalan
        $period = $request->get('period')
            ? Carbon::parse($request->get('period'))->startOfMonth()
            : now()->startOfMonth();

        // helper info (opsional): OS saat ini berdasarkan position_date terakhir yang ada
        $helper = $this->buildHelper($user);

        return view('kpi.marketing.targets.form', [
            'mode'   => 'create',
            'target' => new MarketingKpiTarget([
                'period' => $period->toDateString(),
                'weight_os' => 60,
                'weight_noa'=> 40,
            ]),
            'helper' => $helper,
        ]);
    }

    public function store(StoreMarketingTargetRequest $request)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $period = Carbon::parse($request->period)->startOfMonth()->toDateString();

        // 1 orang hanya 1 target per period (unique), jadi pakai updateOrCreate
        $target = MarketingKpiTarget::query()->updateOrCreate(
            [
                'period'  => $period,
                'user_id' => $user->id,
            ],
            [
                'branch_code'      => $request->branch_code ?: null,
                'target_os_growth' => $request->target_os_growth,
                'target_noa'       => $request->target_noa,
                'weight_os'        => $request->weight_os ?? 60,
                'weight_noa'       => $request->weight_noa ?? 40,
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

        // AO hanya boleh edit target miliknya
        abort_unless((int)$target->user_id === (int)$user->id, 403);

        // kalau sudah submit/approved/locked -> tidak boleh edit
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

        $target->update([
            'branch_code'      => $request->branch_code ?: null,
            'target_os_growth' => $request->target_os_growth,
            'target_noa'       => $request->target_noa,
            'weight_os'        => $request->weight_os ?? $target->weight_os,
            'weight_noa'       => $request->weight_noa ?? $target->weight_noa,
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

            // cek leader langsung dari OrgAssignment (aktif)
            $oa = OrgAssignment::query()
                ->active()
                ->where('user_id', $me->id)
                ->first();

            // default: butuh TL (lebih aman)
            $next = MarketingKpiTarget::STATUS_PENDING_TL;

            // kalau tidak punya TL / langsung ke KASI => skip TL
            // indikatornya: leader_role bukan TL/TLL/TLR/TLF
            if ($oa) {
                $leaderRole = strtoupper((string)$oa->leader_role);
                if (!in_array($leaderRole, ['TL','TLL','TLR','TLF'], true)) {
                    $next = MarketingKpiTarget::STATUS_PENDING_KASI;
                }
            }

            $t->status = $next;
            $t->proposed_by = $me->id;
            $t->save();
        });

        return back()->with('status', 'Target berhasil disubmit & masuk inbox approval.');
    }

    /**
     * Helper info untuk AO supaya input target lebih masuk akal.
     */
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
        $oa = \App\Models\OrgAssignment::query()
            ->active()
            ->where('user_id', $userId)
            ->first();

        if (!$oa) return true;

        return in_array(strtoupper((string)$oa->leader_role), ['TL','TLL','TLR','TLF'], true);
    }

}
