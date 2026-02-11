<?php

namespace App\Http\Controllers;

use App\Models\ActionSchedule;
use App\Models\CaseAction;
use App\Models\LoanAccount;
use App\Models\NplCase;
use App\Models\RkhDetail;
use App\Models\RkhVisitLog;
use App\Models\VisitLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RkhVisitController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    // ====== (A) Prospect/Non-NPL Visit Form ======
    public function create(Request $request, RkhDetail $detail)
    {
        $detail->load('header');

        $recent = $detail->visitLogs()
            ->with('user')
            ->orderByDesc('visited_at')
            ->limit(5)
            ->get();

        return view('rkh_visits.create', compact('detail', 'recent'));
    }

    public function store(Request $request, RkhDetail $detail)
    {
        $data = $request->validate([
            'visited_at'      => ['nullable', 'date'],
            'latitude'        => ['nullable', 'numeric'],
            'longitude'       => ['nullable', 'numeric'],
            'location_note'   => ['nullable', 'string', 'max:255'],
            'notes'           => ['required', 'string'],
            'agreement'       => ['nullable', 'string', 'max:255'],
            'next_action'     => ['nullable', 'string', 'max:255'],
            'next_action_due' => ['nullable', 'date'],
            'photo'           => ['nullable', 'image', 'max:2048'],
        ]);

        $visitedAt = !empty($data['visited_at'])
            ? Carbon::parse($data['visited_at'])
            : now();

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('rkh-visit-photos', 'public');
        }

        RkhVisitLog::create([
            'rkh_detail_id'  => $detail->id,
            'user_id'        => $request->user()->id,
            'visited_at'     => $visitedAt,
            'latitude'       => $data['latitude'] ?? null,
            'longitude'      => $data['longitude'] ?? null,
            'location_note'  => $data['location_note'] ?? null,
            'notes'          => $data['notes'],
            'agreement'      => $data['agreement'] ?? null,
            'next_action'    => $data['next_action'] ?? null,
            'next_action_due'=> $data['next_action_due'] ?? null,
            'photo_path'     => $photoPath,
        ]);

        return redirect()
            ->route('rkh.show', $detail->rkh_id)
            ->with('status', 'Kunjungan RKH tersimpan. Jika sudah ada rekening, lakukan Link account_no agar masuk timeline penanganan.');
    }

    // ====== (B) Link Account + Promote to Timeline Penanganan ======
    public function linkAccount(Request $request, RkhDetail $detail)
    {
        $data = $request->validate([
            'account_no' => ['required', 'string', 'max:255'],
        ]);

        $accountNo = trim($data['account_no']);

        $loan = LoanAccount::query()
            ->where('account_no', $accountNo)
            ->firstOrFail();

        // ensure open case for this loan
        $case = NplCase::query()
            ->where('loan_account_id', $loan->id)
            ->where('status', 'open')
            ->whereNull('closed_at')
            ->first();

        if (!$case) {
            $case = NplCase::create([
                'loan_account_id' => $loan->id,
                'pic_user_id'     => $request->user()->id,
                'status'          => 'open',
                'priority'        => 'normal',
                'opened_at'       => now()->toDateString(),
                'summary'         => 'Auto-created from RKH (link account)',
            ]);
        }

        // ensure visit schedule (pending) for this rkh detail
        $detail->load('header');

        $scheduledAt = Carbon::parse($detail->header->tanggal)
            ->setTimeFromTimeString($detail->jam_mulai);

        $schedule = ActionSchedule::query()
            ->where('type', 'visit')
            ->whereIn('status', ['pending','in_progress'])
            ->where('source_system', 'rkh')
            ->where('source_ref_id', $detail->id)
            ->first();

        if (!$schedule) {
            $schedule = ActionSchedule::create([
                'npl_case_id'   => $case->id,
                'type'          => 'visit',
                'title'         => 'Kunjungan Lapangan',
                'notes'         => 'LKH RKH: ' . ($detail->nama_nasabah ?? '-'),
                'scheduled_at'  => $scheduledAt,
                'status'        => 'pending',
                'created_by'    => $request->user()->id,
                'source_system' => 'rkh',
                'source_ref_id' => $detail->id,
            ]);
        }

        // update rkh detail link
        $detail->account_no = $accountNo;
        $detail->linked_npl_case_id = $case->id;
        $detail->visit_schedule_id = $schedule->id; // ini ActionSchedule ID
        $detail->save();

        // promote all unpromoted rkh_visit_logs -> CaseAction (and optionally VisitLog)
        $userId = $request->user()->id;

        DB::transaction(function () use ($detail, $case, $schedule, $userId) {

            $logs = $detail->visitLogs()
                ->whereNull('promoted_at')
                ->orderBy('visited_at')
                ->lockForUpdate()
                ->get();

            foreach ($logs as $log) {
                // 1) create VisitLog (optional but bagus agar muncul di modul visits recent)
                $visit = VisitLog::create([
                    'npl_case_id'        => $case->id,
                    'action_schedule_id' => $schedule->id,
                    'user_id'            => $log->user_id,
                    'visited_at'         => $log->visited_at,
                    'latitude'           => $log->latitude,
                    'longitude'          => $log->longitude,
                    'location_note'      => $log->location_note,
                    'notes'              => $log->notes,
                    'agreement'          => $log->agreement,
                    'photo_path'         => $log->photo_path,
                ]);

                // 2) create CaseAction for timeline
                $action = CaseAction::create([
                    'npl_case_id'   => $case->id,
                    'user_id'       => $log->user_id,

                    'source_system' => 'rkh_visit',
                    'source_ref_id' => $log->id,

                    'action_type'   => 'visit',
                    'action_at'     => $log->visited_at,
                    'description'   => $log->notes,
                    'result'        => $log->agreement ?: 'DONE',

                    'next_action'     => $log->next_action,
                    'next_action_due' => $log->next_action_due,

                    'meta' => [
                        'rkh_detail_id'      => $detail->id,
                        'rkh_visit_log_id'   => $log->id,
                        'visit_log_id'       => $visit->id,
                        'action_schedule_id' => $schedule->id,
                        'has_photo'          => !empty($log->photo_path),
                        'latitude'           => $log->latitude,
                        'longitude'          => $log->longitude,
                        'location_note'      => $log->location_note,
                    ],
                ]);

                $log->promoted_at = now();
                $log->promoted_to_case_id = $case->id;
                $log->promoted_action_id = $action->id;
                $log->save();
            }
        });

        return redirect()
            ->route('rkh.show', $detail->rkh_id)
            ->with('status', 'Account berhasil dilink & riwayat kunjungan RKH sudah masuk timeline penanganan.');
    }
}
