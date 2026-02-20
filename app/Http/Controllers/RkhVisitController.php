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

        $accountNo = trim((string) $data['account_no']);
        if ($accountNo === '') {
            return back()->withErrors(['account_no' => 'Account no wajib diisi.']);
        }

        // Pastikan detail + header ada (untuk scheduledAt)
        $detail->load('header');
        if (!$detail->header) {
            abort(500, 'RKH header tidak ditemukan untuk detail ini.');
        }

        // ==== 1) Pastikan LoanAccount valid ====
        $loan = LoanAccount::query()
            ->whereRaw('TRIM(account_no) = ?', [$accountNo])
            ->firstOrFail();

        // ==== 2) Ensure open NPL case untuk loan ini ====
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

        // ==== 3) Ensure ActionSchedule (visit) utk rkh_detail ini ====
        $scheduledAt = Carbon::parse($detail->header->tanggal)
            ->setTimeFromTimeString($detail->jam_mulai);

        $schedule = ActionSchedule::query()
            ->where('type', 'visit')
            ->whereIn('status', ['pending', 'in_progress'])
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
        } else {
            // optional sync jadwal
            $schedule->scheduled_at = $scheduledAt;
            $schedule->save();
        }

        // ==== 4) Update link di RKH detail (PENTING: pakai kolom yg benar) ====
        $detail->account_no          = $accountNo;
        $detail->linked_npl_case_id  = $case->id;

        // ✅ simpan ActionSchedule id di kolom baru
        if (Schema::hasColumn('rkh_details', 'action_schedule_id')) {
            $detail->action_schedule_id = $schedule->id;
        }

        // ⚠️ JANGAN isi visit_schedule_id dengan ActionSchedule id lagi
        // $detail->visit_schedule_id = $schedule->id; // ❌ STOP

        $detail->save();

        // ==== 5) Promote semua rkh_visit_logs yg belum promoted ke timeline ====
        $userId = (int) $request->user()->id;

        DB::transaction(function () use ($detail, $case, $schedule, $userId) {

            $logs = $detail->visitLogs()
                ->whereNull('promoted_at')
                ->orderBy('visited_at')
                ->lockForUpdate()
                ->get();

            foreach ($logs as $log) {

                // (A) buat VisitLog (agar muncul di modul visits/recent)
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

                // (B) masuk timeline penanganan (CaseAction)
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

                // (C) tanda promoted
                $log->promoted_at        = now();
                $log->promoted_to_case_id= $case->id;
                $log->promoted_action_id = $action->id;
                $log->save();
            }
        });

        return redirect()
            ->route('rkh.show', $detail->rkh_id)
            ->with('status', 'Account berhasil dilink & riwayat kunjungan RKH sudah masuk timeline penanganan.');
    }

    public function start(Request $request, RkhDetail $detail)
    {
        $detail->load('header');

        if (!$detail->header) {
            abort(500, 'RKH header tidak ditemukan untuk detail ini.');
        }

        // =========================================================
        // GUARD 1) OWNER ONLY (anti bypass)
        // =========================================================
        abort_unless(
            (int) $detail->header->user_id === (int) auth()->id(),
            403
        );

        // =========================================================
        // GUARD 2) HANYA BISA ISI LKH SETELAH DI-APPROVE TL
        // =========================================================
        abort_unless(
            (string) $detail->header->status === 'approved',
            403
            // atau: abort(403, 'LKH hanya bisa diisi setelah RKH di-approve TL.');
        );

        // =========================================================
        // (Opsional) GUARD 3) Hanya untuk tanggal hari ini
        // kalau mau disiplin, aktifkan ini:
        // =========================================================
        // abort_unless(
        //     Carbon::parse($detail->header->tanggal)->isToday(),
        //     403
        // );

        // === 1) Kalau sudah ada account_no => existing nasabah => masuk flow timeline (ActionSchedule / Visits)
        $accountNo = trim((string) ($detail->account_no ?? ''));

        if ($accountNo !== '') {

            // ensure open case
            $case = $this->ensureCaseByAccountNo($accountNo);

            // ensure action schedule (visit) utk sumber rkh_detail ini
            $scheduledAt = Carbon::parse($detail->header->tanggal)
                ->setTimeFromTimeString($detail->jam_mulai);

            $schedule = $this->ensureActionScheduleFromRkhDetail(
                $detail,
                (int) $case->id,
                $scheduledAt
            );

            // sync link di rkh_details (pakai kolom yang benar: linked_npl_case_id)
            $detail->linked_npl_case_id = (int) $case->id;

            // ⚠️ penting: ActionSchedule ID (bukan visit_schedule_id)
            // aman: cek kolom ada di table (runtime)
            if (\Illuminate\Support\Facades\Schema::hasColumn('rkh_details', 'action_schedule_id')) {
                $detail->action_schedule_id = (int) $schedule->id;
            }

            $detail->save();

            // redirect ke form Visit (timeline)
            return redirect()->route('ro_visits.create', [
                'rkh_detail_id' => $detail->id,
                'back' => url()->previous(),
            ]);
        }

        // === 2) Kalau prospect / belum ada account_no => pakai form RKH visit log (rkh_visit_logs)
        return $this->create($request, $detail);
    }

    private function ensureCaseByAccountNo(string $accountNo): NplCase
    {
        $accountNo = trim($accountNo);

        $loan = LoanAccount::query()
            ->where('account_no', $accountNo)
            ->firstOrFail();

        $case = NplCase::query()
            ->where('loan_account_id', $loan->id)
            ->where('status', 'open')
            ->whereNull('closed_at')
            ->first();

        if ($case) return $case;

        return NplCase::create([
            'loan_account_id' => $loan->id,
            'pic_user_id'     => auth()->id(),
            'status'          => 'open',
            'priority'        => 'normal',
            'opened_at'       => now()->toDateString(),
            'summary'         => 'Auto-created from RKH visit',
        ]);
    }

    private function ensureActionScheduleFromRkhDetail(RkhDetail $detail, int $caseId, Carbon $scheduledAt): ActionSchedule
    {
        $existing = ActionSchedule::query()
            ->where('type', 'visit')
            ->whereIn('status', ['pending','in_progress'])
            ->where('source_system', 'rkh')
            ->where('source_ref_id', $detail->id)
            ->first();

        if ($existing) {
            $existing->scheduled_at = $scheduledAt;
            $existing->save();
            return $existing;
        }

        return ActionSchedule::create([
            'npl_case_id'   => $caseId,
            'type'          => 'visit',
            'title'         => 'Kunjungan Lapangan',
            'notes'         => 'LKH RKH: ' . ($detail->nama_nasabah ?? '-'),
            'scheduled_at'  => $scheduledAt,
            'status'        => 'pending',
            'created_by'    => auth()->id(),
            'source_system' => 'rkh',
            'source_ref_id' => $detail->id,
        ]);
    }

}
