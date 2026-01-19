<?php

namespace App\Http\Controllers\Kti;

use App\Http\Controllers\Controller;
use App\Models\NplCase;
use App\Models\CaseResolutionTarget;
use App\Services\Crms\ResolutionTargetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class KtiResolutionTargetController extends Controller
{
    public function index(Request $request)
    {
        // gate kasar di awal (biar aman)
        $this->authorize('forceCreateByKti', [CaseResolutionTarget::class, new NplCase()]);

        $today = now()->toDateString();
        $q     = trim((string) $request->get('q', ''));

        /**
         * Subquery: ambil 1 target aktif per case (pakai MAX(id) saat is_active=1)
         */
        $activeTargetSub = DB::table('case_resolution_targets as t')
            ->select(
                't.npl_case_id',
                't.id as target_id',
                't.target_date',
                't.status as target_status',
                't.strategy',
                't.target_outcome',
                DB::raw('COALESCE(t.activated_at, t.created_at) as target_anchor_at')
            )
            ->where('t.is_active', 1);

        $activeTargetPick = DB::table(DB::raw("({$activeTargetSub->toSql()}) as at0"))
            ->mergeBindings($activeTargetSub)
            ->select('at0.npl_case_id', DB::raw('MAX(at0.target_id) as target_id'))
            ->groupBy('at0.npl_case_id');

        $activeTargetFinal = DB::table('case_resolution_targets as t')
            ->joinSub($activeTargetPick, 'pick', function ($j) {
                $j->on('pick.target_id', '=', 't.id');
            })
            ->select(
                't.npl_case_id',
                't.id as target_id',
                't.target_date',
                't.status as target_status',
                't.strategy',
                't.target_outcome',
                DB::raw('COALESCE(t.activated_at, t.created_at) as target_anchor_at')
            );

        $rows = DB::table('npl_cases as c')
            ->leftJoin('loan_accounts as la', 'la.id', '=', 'c.loan_account_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.pic_user_id')
            ->leftJoinSub($activeTargetFinal, 'at', function ($j) {
                $j->on('at.npl_case_id', '=', 'c.id');
            })
            ->select(
                'c.id',
                'c.status as case_status',
                'c.pic_user_id',
                DB::raw("COALESCE(la.customer_name, '-') as debtor_name"),
                DB::raw("COALESCE(la.cif, '-') as cif"),
                DB::raw("COALESCE(la.account_no, '-') as account_no"),
                DB::raw("COALESCE(u.name, '-') as pic_name"),
                'la.dpd',
                'la.kolek',
                'la.outstanding',
                'at.target_id',
                'at.target_date',
                'at.target_status',
                'at.strategy',
                'at.target_outcome',
                'at.target_anchor_at',
                DB::raw("
                    CASE
                        WHEN at.target_id IS NULL THEN 'NO_TARGET'
                        WHEN at.target_date < '{$today}'
                             AND LOWER(COALESCE(at.target_status,'')) NOT IN ('done','closed','completed','executed','settled')
                             THEN 'OVERDUE'
                        ELSE 'OK'
                    END AS issue
                "),
                DB::raw("
                    CASE
                        WHEN at.target_id IS NOT NULL
                         AND at.target_date < '{$today}'
                         AND LOWER(COALESCE(at.target_status,'')) NOT IN ('done','closed','completed','executed','settled')
                        THEN DATEDIFF('{$today}', at.target_date)
                        ELSE NULL
                    END AS overdue_days
                ")
            )
            // biasanya KTI fokus NPL yg open saja (kalau mau semua, hapus ini)
            ->where('c.status', 'open');

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows->where(function ($w) use ($needle) {
                $w->whereRaw("LOWER(COALESCE(la.customer_name,'')) LIKE ?", ["%{$needle}%"])
                  ->orWhereRaw("LOWER(COALESCE(la.cif,'')) LIKE ?", ["%{$needle}%"])
                  ->orWhereRaw("LOWER(COALESCE(la.account_no,'')) LIKE ?", ["%{$needle}%"])
                  ->orWhereRaw("LOWER(COALESCE(u.name,'')) LIKE ?", ["%{$needle}%"]);
            });
        }

        // Urutan: NO_TARGET dulu biar KTI cepat input, lalu OVERDUE, lalu OK
        $rows->orderByRaw("
            CASE issue
                WHEN 'NO_TARGET' THEN 1
                WHEN 'OVERDUE' THEN 2
                ELSE 9
            END ASC
        ")->orderByDesc('overdue_days')
          ->orderByDesc('c.id');

        $rows = $rows->paginate(15)->withQueryString();

        return view('kti.targets.index', compact('rows', 'q'));
    }

    public function show(NplCase $case)
    {
        $this->authorize('forceCreateByKti', [CaseResolutionTarget::class, $case]);

        // pakai DB join supaya gak butuh relasi eloquent
        $caseRow = DB::table('npl_cases as c')
            ->leftJoin('loan_accounts as la', 'la.id', '=', 'c.loan_account_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.pic_user_id')
            ->where('c.id', $case->id)
            ->select(
                'c.id',
                'c.status as case_status',
                DB::raw("COALESCE(la.customer_name, '-') as debtor_name"),
                DB::raw("COALESCE(la.cif, '-') as cif"),
                DB::raw("COALESCE(la.account_no, '-') as account_no"),
                DB::raw("COALESCE(u.name, '-') as pic_name"),
                'la.dpd',
                'la.kolek',
                'la.outstanding'
            )
            ->first();

        $activeTarget = DB::table('case_resolution_targets')
            ->where('npl_case_id', $case->id)
            ->where('is_active', 1)
            ->orderByDesc('id')
            ->first();

        $history = DB::table('case_resolution_targets')
            ->where('npl_case_id', $case->id)
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('kti.targets.show', compact('case', 'caseRow', 'activeTarget', 'history'));
    }

    public function store(Request $request, NplCase $case, ResolutionTargetService $svc)
    {
        $this->authorize('forceCreateByKti', [CaseResolutionTarget::class, $case]);

        $data = $request->validate([
            'target_date'    => ['required', 'date', 'after_or_equal:today'],
            'strategy'       => ['nullable', Rule::in([
                'lelang',
                'ayda',
                'intensif',
                'rs',
                'jual_jaminan',
            ])],
            'reason'         => ['nullable', 'string', 'max:500'],
            'target_outcome' => ['required', Rule::in(['lunas', 'lancar'])],
        ]);


        foreach (['strategy', 'reason', 'target_outcome'] as $f) {
            if (array_key_exists($f, $data) && is_string($data[$f])) {
                $data[$f] = trim($data[$f]);
                if ($data[$f] === '') $data[$f] = null;
            }
        }

        $svc->forceActivateByKti(
            case: $case,
            targetDate: $data['target_date'],
            strategy: $data['strategy'] ?? null,
            inputBy: auth()->id(),
            reason: $data['reason'] ?? null,
            targetOutcome: $data['target_outcome'],
        );

        return redirect()
            ->route('kti.targets.show', $case)
            ->with('success', 'Target penyelesaian berhasil diinput oleh KTI dan langsung AKTIF.');
    }
}
