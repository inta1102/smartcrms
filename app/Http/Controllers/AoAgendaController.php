<?php

namespace App\Http\Controllers;

use App\Models\AoAgenda;
use App\Models\CaseAction;
use App\Models\NplCase;
use App\Services\Ao\AoAgendaProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AoAgendaController extends Controller
{
    /**
     * “1 pintu role”:
     * - Jangan baca $user->level lagi.
     * - Role check lewat helper di User model (mis: isSupervisor()).
     */
    protected function isSupervisor(): bool
    {
        $user = auth()->user();
        if (!$user) return false;

        // ✅ REKOMENDASI: taruh logika ini di User model: $user->isSupervisor()
        // Di controller kita cukup panggil itu.
        if (method_exists($user, 'isSupervisor')) {
            return (bool) $user->isSupervisor();
        }

        // Fallback jika helper belum ada (sementara):
        // Jika kamu sudah punya helper hasAnyRole(array $roles) di User model, pakai itu.
        if (method_exists($user, 'hasAnyRole')) {
            return (bool) $user->hasAnyRole(['TL', 'KBL', 'KTI', 'KSR', 'KSL', 'DIREKSI']);
        }

        return false;
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', AoAgenda::class);

        $u     = auth()->user();
        $level = strtolower(trim($u?->roleValue() ?? ''));

        // Field staff yg harus dibatasi hanya case PIC dia
        $isFieldStaff = in_array($level, ['ao','so','fe','be'], true);

        // ✅ 1 query utama (jangan bikin 2 query yg kebuang)
        $q = AoAgenda::query()
            ->with(['nplCase.loanAccount']);

        // ✅ Batasi data untuk non-supervisor
        // - Supervisor: boleh lihat semua
        // - FieldStaff: hanya agenda untuk case yg dia PIC
        if (!$this->isSupervisor() && $isFieldStaff && $u) {
            $q->whereHas('nplCase', fn ($cq) => $cq->where('pic_user_id', $u->id));
        }

        $filters = [
            'status'      => $request->input('status'),
            'agenda_type' => $request->input('agenda_type'),
            'case_id'     => $request->input('case_id'),
        ];

        if (!empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        if (!empty($filters['agenda_type'])) {
            $q->where('agenda_type', $filters['agenda_type']);
        }

        if (!empty($filters['case_id'])) {
            $q->where('npl_case_id', $filters['case_id']);
        }

        // default: tampilkan yang belum done/cancelled
        if (empty($filters['status'])) {
            $q->whereNotIn('status', [AoAgenda::STATUS_DONE, AoAgenda::STATUS_CANCELLED]);
        }

        $agendas = $q
            ->orderByRaw("FIELD(status,'overdue','planned','in_progress','done','cancelled')")
            ->orderByRaw("CASE WHEN due_at IS NULL THEN 1 ELSE 0 END")
            ->orderBy('due_at')
            ->paginate(20)
            ->withQueryString();

        return view('ao_agendas.index', compact('agendas', 'filters'));
    }

    public function edit(AoAgenda $agenda)
    {
        $this->authorize('view', $agenda);
        $this->authorize('update', $agenda);

        $lastAction = CaseAction::query()
            ->where('ao_agenda_id', $agenda->id)
            ->latest('action_at')
            ->first();

        return view('ao_agendas.edit', [
            'agenda'     => $agenda,
            'lastAction' => $lastAction,
        ]);
    }

    /**
     * ✅ Edit data saja (jadwal/notes/evidence)
     * ❌ Tidak mengubah status. Status lewat tombol aksi.
     */
    public function update(Request $request, AoAgenda $agenda)
    {
        $this->authorize('update', $agenda);

        $validated = $request->validate([
            'planned_at'     => ['nullable', 'date'],
            'due_at'         => ['nullable', 'date'],
            'notes'          => ['nullable', 'string', 'max:4000'],

            'evidence_file'  => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'evidence_notes' => ['nullable', 'string', 'max:500'],
        ]);

        // Guard planned <= due
        if (!empty($validated['planned_at']) && !empty($validated['due_at'])) {
            if (strtotime($validated['due_at']) < strtotime($validated['planned_at'])) {
                return back()
                    ->withErrors(['due_at' => 'Due At harus >= Planned At'])
                    ->withInput();
            }
        }

        $userId = Auth::id();

        DB::transaction(function () use ($agenda, $validated, $request, $userId) {

            $changingSchedule = false;

            if (array_key_exists('planned_at', $validated)) {
                $new = $validated['planned_at'] ? date('Y-m-d H:i:s', strtotime($validated['planned_at'])) : null;
                $old = $agenda->planned_at?->format('Y-m-d H:i:s');
                if ($new !== $old) {
                    $agenda->planned_at = $validated['planned_at'];
                    $changingSchedule = true;
                }
            }

            if (array_key_exists('due_at', $validated)) {
                $new = $validated['due_at'] ? date('Y-m-d H:i:s', strtotime($validated['due_at'])) : null;
                $old = $agenda->due_at?->format('Y-m-d H:i:s');
                if ($new !== $old) {
                    $agenda->due_at = $validated['due_at'];
                    $changingSchedule = true;
                }
            }

            if ($changingSchedule) {
                $agenda->rescheduled_at = now();
                $agenda->rescheduled_by = $userId;
            }

            if (array_key_exists('notes', $validated)) {
                $agenda->notes = $validated['notes'];
            }

            if ($request->hasFile('evidence_file')) {
                $path = $request->file('evidence_file')->store('ao_agendas/evidence', 'public');
                $agenda->evidence_path = $path;
            }

            if (array_key_exists('evidence_notes', $validated)) {
                $agenda->evidence_notes = $validated['evidence_notes'];
            }

            $agenda->updated_by = $userId;
            $agenda->save();
        });

        return redirect()
            ->route('ao-agendas.edit', $agenda)
            ->with('success', 'Data agenda berhasil diupdate.');
    }

    // =========================
    // ✅ AKSI STATUS (via tombol)
    // =========================

    public function start(Request $request, AoAgenda $agenda)
    {
        $this->authorize('update', $agenda);

        $userId = Auth::id();

        DB::transaction(function () use ($agenda, $userId) {
            if (in_array($agenda->status, [AoAgenda::STATUS_DONE, AoAgenda::STATUS_CANCELLED], true)) {
                abort(422, 'Agenda sudah selesai / dibatalkan.');
            }

            if ($agenda->status !== AoAgenda::STATUS_IN_PROGRESS) {
                $agenda->status = AoAgenda::STATUS_IN_PROGRESS;
            }

            $agenda->started_at ??= now();
            $agenda->started_by ??= $userId;
            $agenda->updated_by = $userId;
            $agenda->save();
        });

        $case = NplCase::findOrFail($agenda->npl_case_id);

        // Normalisasi type
        $type = strtolower(trim((string) $agenda->agenda_type));

        $caseUrl = function (array $q = []) use ($case) {
            $base = route('cases.show', $case);
            if (!empty($q)) {
                $base .= (str_contains($base, '?') ? '&' : '?') . http_build_query($q);
            }
            return redirect()->to($base)->with('success', 'Agenda dimulai.');
        };

        return match ($type) {
            'wa' => $caseUrl([
                'tab'    => 'persuasif',
                'agenda' => $agenda->id,
                'preset' => 'whatsapp',
            ]),

            'visit' => redirect()->to(
                route('cases.visits.quickStart', $case) . '?' . http_build_query([
                    'agenda' => $agenda->id,
                ])
            )->with('success', 'Agenda dimulai. Silakan isi form kunjungan.'),

            'evaluation' => $caseUrl([
                'tab'    => 'lit',
                'agenda' => $agenda->id,
            ]),

            default => $caseUrl([
                'tab'    => 'persuasif',
                'agenda' => $agenda->id,
            ]),
        };
    }

    public function complete(Request $request, AoAgenda $agenda)
    {
        $this->authorize('update', $agenda);

        if (in_array($agenda->status, [AoAgenda::STATUS_DONE, AoAgenda::STATUS_CANCELLED], true)) {
            abort(422, 'Agenda sudah selesai / dibatalkan.');
        }

        $userId = auth()->id();

        $hasLog = CaseAction::query()
            ->where('ao_agenda_id', $agenda->id)
            ->exists();

        if ((int) $agenda->evidence_required === 1) {
            if (!$hasLog) {
                return $this->redirectToActionFromAgenda($agenda)
                    ->with('warning', 'Belum ada log tindakan. Silakan isi tindakan dulu sebelum menutup agenda.');
            }
        }

        DB::transaction(function () use ($agenda, $userId) {
            $agenda->status       = AoAgenda::STATUS_DONE;
            $agenda->completed_at = now();
            $agenda->completed_by = $userId;
            $agenda->updated_by   = $userId;
            $agenda->save();
        });

        return back()->with('success', 'Agenda selesai.');
    }

    /**
     * Redirect pintar jika user klik Selesai tapi belum ada bukti proses.
     */
    protected function redirectToActionFromAgenda(AoAgenda $agenda)
    {
        $case = NplCase::findOrFail($agenda->npl_case_id);

        $type = strtolower(trim((string) $agenda->agenda_type));

        $caseUrl = function (array $q) use ($case) {
            $base = route('cases.show', $case);
            $qs   = http_build_query($q);
            return redirect()->to($base . '?' . $qs);
        };

        return match ($type) {
            'wa' => $caseUrl([
                'tab'    => 'persuasif',
                'agenda' => $agenda->id,
                'preset' => 'whatsapp',
            ]),

            'visit' => redirect()->to(
                route('cases.visits.quickStart', $case) . '?agenda=' . $agenda->id
            ),

            'evaluation' => $caseUrl([
                'tab'    => 'lit',
                'agenda' => $agenda->id,
            ]),

            default => $caseUrl([
                'tab'    => 'persuasif',
                'agenda' => $agenda->id,
            ]),
        };
    }

    public function reschedule(Request $request, AoAgenda $agenda, AoAgendaProgressService $svc)
    {
        $this->authorize('reschedule', $agenda);

        $data = $request->validate([
            'due_at'  => ['required', 'date'],
            'reason'  => ['required', 'string', 'max:255'],
        ]);

        $svc->reschedule($agenda, auth()->id(), $data['due_at'], $data['reason']);

        return back()->with('success', 'Agenda berhasil di-reschedule.');
    }

    public function cancel(Request $request, AoAgenda $agenda, AoAgendaProgressService $svc)
    {
        $this->authorize('cancel', $agenda);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $svc->cancel($agenda, auth()->id(), $data['reason']);

        return back()->with('success', 'Agenda dibatalkan.');
    }
}
