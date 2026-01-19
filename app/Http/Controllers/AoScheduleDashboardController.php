<?php

namespace App\Http\Controllers;

use App\Models\ActionSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AoScheduleDashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * BACKWARD COMPAT:
     * kalau ada route lama yang manggil index()
     */
    public function index(Request $request)
    {
        return $this->my($request);
    }

    /**
     * Agenda saya (AO/FE/SO/BE) -> berdasarkan PIC case (npl_cases.pic_user_id)
     */
    public function my(Request $request)
    {
        $u = auth()->user();
        abort_unless($u, 403);

        $today      = now();
        $todayStart = $today->copy()->startOfDay();
        $todayEnd   = $today->copy()->endOfDay();
        $upTo       = $today->copy()->addDays(14)->endOfDay();

        $base = ActionSchedule::query()
            ->with([
                'nplCase.loanAccount',
                'nplCase.actions' => function ($q) {
                    // anti casing: SP1 vs sp1
                    $q->whereRaw('LOWER(action_type) IN (?,?,?,?,?)', ['sp1','sp2','sp3','spt','spjad'])
                      ->orderByDesc('action_at');
                },
            ])
            ->where('status', 'pending')
            ->whereHas('nplCase', fn($q) => $q->where('pic_user_id', $u->id));

        $overdue = $this->pickOnePerCase(
            (clone $base)->where('scheduled_at', '<', $todayStart)->orderBy('scheduled_at')->get()
        );

        $todayList = $this->pickOnePerCase(
            (clone $base)->whereBetween('scheduled_at', [$todayStart, $todayEnd])->orderBy('scheduled_at')->get()
        );

        $upcoming = $this->pickOnePerCase(
            (clone $base)->whereBetween('scheduled_at', [$todayEnd->copy()->addSecond(), $upTo])->orderBy('scheduled_at')->get()
        );

        $aoName = $u->name ?? null;
        $aoCode = trim((string)($u->ao_code ?? ''));

        return view('dashboard.ao-agenda', compact('aoName','aoCode','overdue','todayList','upcoming'))
            ->with(['today' => $today]);
    }

    /**
     * Route helper: /ao-agendas/ao (tanpa aoCode)
     * Redirect ke AO pertama yang disupervisi (berdasarkan org_assignments).
     */
    public function pickAo(Request $request)
    {
        $u = auth()->user();
        abort_unless($u, 403);

        // Ambil 1 bawahan (AO) dari org_assignments -> leader_id = user login
        // Asumsi: org_assignments.user_id = staff/AO, leader_id = TL/KASI/Kabag
        $firstAoCode = DB::table('org_assignments as oa')
            ->join('users as usr', 'usr.id', '=', 'oa.user_id')
            ->join('loan_accounts as la', 'la.ao_code', '=', 'usr.employee_code')
            ->where('oa.leader_id', $u->id)
            ->whereNotNull('usr.employee_code')
            ->value('usr.employee_code');

        // fallback kalau tidak ketemu: arahkan ke dashboard supervisi saja
        if (!$firstAoCode) {
            return redirect()->route('supervision.home');
        }

        return redirect()->route('ao-agendas.ao', ['aoCode' => $firstAoCode]);
    }

    /**
     * Agenda tindakan untuk AO tertentu (TL/KASI/Direksi)
     */
    public function forAo(Request $request, string $aoCode)
    {
        $u = auth()->user();
        abort_unless($u, 403);

        $aoCode = trim($aoCode);
        abort_if($aoCode === '', 404);

        $today      = now();
        $todayStart = $today->copy()->startOfDay();
        $todayEnd   = $today->copy()->endOfDay();
        $upTo       = $today->copy()->addDays(14)->endOfDay();

        $base = ActionSchedule::query()
            ->with([
                'nplCase.loanAccount',
                'nplCase.actions' => function ($q) {
                    $q->whereRaw('LOWER(action_type) IN (?,?,?,?,?)', ['sp1','sp2','sp3','spt','spjad'])
                      ->orderByDesc('action_at');
                },
            ])
            ->where('status', 'pending')
            ->whereHas('nplCase.loanAccount', fn($q) => $q->where('ao_code', $aoCode));

        $overdue = $this->pickOnePerCase(
            (clone $base)->where('scheduled_at', '<', $todayStart)->orderBy('scheduled_at')->get()
        );

        $todayList = $this->pickOnePerCase(
            (clone $base)->whereBetween('scheduled_at', [$todayStart, $todayEnd])->orderBy('scheduled_at')->get()
        );

        $upcoming = $this->pickOnePerCase(
            (clone $base)->whereBetween('scheduled_at', [$todayEnd->copy()->addSecond(), $upTo])->orderBy('scheduled_at')->get()
        );

        $aoName = null;

        return view('dashboard.ao-agenda', compact('aoName','aoCode','overdue','todayList','upcoming'))
            ->with(['today' => $today]);
    }

    /**
     * BACKWARD COMPAT:
     * kalau ada route lama yang manggil ao()
     */
    public function ao(string $aoCode, Request $request)
    {
        return $this->forAo($request, $aoCode);
    }

    /**
     * Pilih 1 schedule per case (anti dobel per debitur).
     * Prioritas: spjad > spt > sp3 > sp2 > sp1 > visit > follow_up
     */
    protected function pickOnePerCase(Collection $items): Collection
    {
        $priority = [
            'spjad'     => 70,
            'spt'       => 60,
            'sp3'       => 50,
            'sp2'       => 40,
            'sp1'       => 30,
            'visit'     => 20,
            'follow_up' => 10,
        ];

        return $items
            ->groupBy('npl_case_id')
            ->map(function (Collection $group) use ($priority) {
                return $group
                    ->sort(function ($a, $b) use ($priority) {
                        $pa = $priority[$a->type] ?? 0;
                        $pb = $priority[$b->type] ?? 0;

                        // 1) prioritas paling tinggi menang
                        if ($pa !== $pb) return $pb <=> $pa;

                        // 2) kalau sama, yang scheduled_at lebih kecil dulu (lebih urgent)
                        $ta = $a->scheduled_at?->timestamp ?? 0;
                        $tb = $b->scheduled_at?->timestamp ?? 0;
                        if ($ta !== $tb) return $ta <=> $tb;

                        // 3) terakhir: id kecil dulu
                        return ($a->id ?? 0) <=> ($b->id ?? 0);
                    })
                    ->first();
            })
            ->values()
            ->sortBy('scheduled_at')
            ->values();
    }
}
