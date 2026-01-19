<?php

namespace App\Http\Controllers;

use App\Models\ActionSchedule;
use App\Models\AoAgenda;
use App\Models\CaseAction;
use App\Models\NplCase;
use App\Models\VisitLog;
use App\Services\CaseScheduler;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VisitController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function create(Request $request, ActionSchedule $schedule)
    {
        abort_unless($schedule->type === 'visit', 404);

        $schedule->load('nplCase.loanAccount');

        $case = $schedule->nplCase;
        $loan = $case->loanAccount;

        // query ?agenda=123
        $agendaId = $request->integer('agenda');

        $recentVisits = $case->visits()
            ->with('user')
            ->orderByDesc('visited_at')
            ->limit(5)
            ->get();

        return view('visits.create', compact('schedule', 'case', 'loan', 'recentVisits', 'agendaId'));
    }

    public function store(Request $request, ActionSchedule $schedule, CaseScheduler $scheduler)
    {
        abort_unless($schedule->type === 'visit', 404);

        $schedule->load('nplCase.loanAccount');
        $case = $schedule->nplCase;

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

            // ✅ ini kunci: hidden input dari form
            'ao_agenda_id'    => ['nullable', 'integer'],
        ]);

        $userId = $request->user()->id;

        $visitedAt = !empty($data['visited_at'])
            ? Carbon::parse($data['visited_at'])
            : now();

        // ✅ resolve agenda (kalau ada)
        $agenda = null;
        $agendaId = (int) ($data['ao_agenda_id'] ?? 0);

        if ($agendaId > 0) {
            $agenda = AoAgenda::query()
                ->where('id', $agendaId)
                ->where('npl_case_id', $case->id)
                ->firstOrFail();

            // “1 pintu role”: siapa yang boleh sentuh agenda, diatur policy AoAgenda
            $this->authorize('progress', $agenda);
        }

        // Upload foto di luar transaction aman (IO)
        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('visit-photos', 'public');
        }

        DB::transaction(function () use (
            $case,
            $schedule,
            $scheduler,
            $agenda,
            $userId,
            $visitedAt,
            $data,
            $photoPath
        ) {
            // 1) Simpan VisitLog
            $visit = VisitLog::create([
                'npl_case_id'        => $case->id,
                'action_schedule_id' => $schedule->id,
                'user_id'            => $userId,
                'visited_at'         => $visitedAt,
                'latitude'           => $data['latitude'] ?? null,
                'longitude'          => $data['longitude'] ?? null,
                'location_note'      => $data['location_note'] ?? null,
                'notes'              => $data['notes'],
                'agreement'          => $data['agreement'] ?? null,
                'photo_path'         => $photoPath,
            ]);

            // 2) Mark schedule DONE (idempotent)
            if ($schedule->status !== 'done') {
                $schedule->status       = 'done';
                $schedule->completed_at = now();
                $schedule->save();
            }

            // 3) Auto-selesaikan agenda visit (jika ada)
            if ($agenda && !in_array($agenda->status, ['done','cancelled'], true)) {
                $agenda->status       = 'done';
                $agenda->completed_at = now();
                $agenda->completed_by = $userId;
                $agenda->updated_by   = $userId;

                // optional: simpan referensi bukti
                // $agenda->evidence_notes = 'VisitLog #' . $visit->id;

                $agenda->save();
            }

            // 4) Buat CaseAction untuk timeline (source visit)
            CaseAction::create([
                'npl_case_id'   => $case->id,
                'ao_agenda_id'  => $agenda?->id,
                'user_id'       => $userId,

                'source_system' => 'visit',
                'source_ref_id' => $visit->id,

                'action_type'   => 'visit',
                'action_at'     => $visitedAt,
                'description'   => $data['notes'],
                'result'        => $data['agreement'] ?: 'DONE',

                'next_action'      => $data['next_action'] ?? null,
                'next_action_due'  => $data['next_action_due'] ?? null,

                'meta' => [
                    'visit_log_id'       => $visit->id,
                    'action_schedule_id' => $schedule->id,
                    'has_photo'          => !empty($photoPath),
                    'latitude'           => $data['latitude'] ?? null,
                    'longitude'          => $data['longitude'] ?? null,
                    'location_note'      => $data['location_note'] ?? null,
                ],
            ]);

            // 5) Generate schedule lanjutan
            $scheduler->generateNextAfterAction($case);
        });

        $base = route('cases.show', $case);

        if ($agenda) {
            $base .= '?' . http_build_query([
                'tab'    => 'persuasif',
                'agenda' => $agenda->id,
            ]);
        }

        return redirect($base)
            ->with('status', 'Log kunjungan berhasil disimpan & jadwal lanjutan dibuat.');
    }

    public function quickStart(Request $request, NplCase $case)
    {
        $agendaId = $request->integer('agenda'); // ?agenda=123
        $userId   = $request->user()->id;

        $agenda = null;

        // ✅ kalau datang dari agenda, start agenda (audit trail)
        if ($agendaId) {
            $agenda = AoAgenda::query()
                ->where('id', $agendaId)
                ->where('npl_case_id', $case->id)
                ->firstOrFail();

            $this->authorize('progress', $agenda);

            if (!in_array($agenda->status, ['done','cancelled'], true)) {
                if ($agenda->status !== 'in_progress') {
                    $agenda->status = 'in_progress';
                }
                $agenda->started_at ??= now();
                $agenda->started_by ??= $userId;
                $agenda->updated_by = $userId;
                $agenda->save();
            }
        }

        // waktu preferensi: planned_at agenda > query at > now
        $preferredAt = $agenda?->planned_at
            ?? ($request->filled('at') ? Carbon::parse($request->input('at')) : now());

        // ✅ schedule resolution
        if ($agenda) {
            // cari schedule visit yang “terikat” agenda
            $schedule = $case->schedules()
                ->where('type', 'visit')
                ->where('status', 'pending')
                ->where('source_system', 'ao_agenda')
                ->where('source_ref_id', $agenda->id)
                ->first();

            if (!$schedule) {
                $schedule = ActionSchedule::create([
                    'npl_case_id'   => $case->id,
                    'type'          => 'visit',
                    'title'         => 'Kunjungan Lapangan',
                    'notes'         => $agenda->title,
                    'scheduled_at'  => $preferredAt,
                    'status'        => 'pending',
                    'created_by'    => $userId,
                    'source_system' => 'ao_agenda',
                    'source_ref_id' => $agenda->id,
                ]);
            } else {
                // sync jadwal agar sesuai preferensi
                $schedule->scheduled_at = $preferredAt;
                $schedule->save();
            }
        } else {
            // fallback: ambil schedule pending terdekat
            $schedule = $case->schedules()
                ->where('type', 'visit')
                ->where('status', 'pending')
                ->orderBy('scheduled_at')
                ->first();

            if (!$schedule) {
                $schedule = ActionSchedule::create([
                    'npl_case_id'   => $case->id,
                    'type'          => 'visit',
                    'title'         => 'Kunjungan Lapangan',
                    'notes'         => null,
                    'scheduled_at'  => $preferredAt,
                    'status'        => 'pending',
                    'created_by'    => $userId,
                    'source_system' => 'manual',
                    'source_ref_id' => null,
                ]);
            } else {
                // optional: kalau request kirim at, kita update
                if ($request->filled('at')) {
                    $schedule->scheduled_at = $preferredAt;
                    $schedule->save();
                }
            }
        }

        // ✅ teruskan agenda ke form visit agar store() bisa simpan ao_agenda_id
        $qs = $agendaId ? ('?agenda=' . $agendaId) : '';
        return redirect()->to(route('visits.start', $schedule) . $qs);
    }
}
