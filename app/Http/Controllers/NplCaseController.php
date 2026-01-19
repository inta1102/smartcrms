<?php

namespace App\Http\Controllers;

use App\Models\AoAgenda;
use App\Models\CaseAction;
use App\Models\LegalAdminChecklist;
use App\Models\NplCase;
use App\Services\CaseActionLegacySpSyncService;
use App\Services\CaseScheduler;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Jobs\SyncLegacySpJob;


class NplCaseController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');

        // ✅ kalau kamu sudah punya NplCasePolicy, ini paling “1 pintu”
        // otomatis map: index->viewAny, show->view, storeAction/storeSp->update, dll
        // Kalau belum ada policy, comment dulu.
        $this->authorizeResource(NplCase::class, 'case');
    }

    /**
     * LIST CASES
     */
    public function index(Request $request)
    {
        $user   = auth()->user();

        $status = (string) $request->get('status', 'open'); // open|closed
        $search = trim((string) $request->get('q', ''));
        $branch = trim((string) $request->get('branch', ''));

        $query = NplCase::query()
            ->with('loanAccount');

        /**
         * ✅ VISIBILITY
         * - selain BE: pakai pagar visibleFor()
         * - khusus BE: lihat legal cases (is_legal=1) + fallback case miliknya (pic_user_id)
         */
        if ($user && method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['BE'])) {
            $query->where(function ($q) use ($user) {
                $q->where('is_legal', 1)
                ->orWhere('pic_user_id', $user->id);
            });
        } else {
            $query->visibleFor($user); // ✅ PAGAR VISIBILITY DEFAULT
        }

        // status open/closed
        $query
            ->when($status === 'open', fn ($q) => $q->whereNull('closed_at'))
            ->when($status === 'closed', fn ($q) => $q->whereNotNull('closed_at'));

        // search rekening/nama
        if ($search !== '') {
            $query->whereHas('loanAccount', function ($q) use ($search) {
                $q->where('account_no', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%");
            });
        }

        // filter cabang
        if ($branch !== '') {
            $query->whereHas('loanAccount', function ($q) use ($branch) {
                $q->where('branch_code', $branch)
                ->orWhere('branch_name', 'like', "%{$branch}%");
            });
        }

        $cases = $query
            ->orderByDesc('priority')
            ->orderByDesc('opened_at')
            ->paginate(15)
            ->withQueryString();

        return view('cases.index', compact('cases', 'status', 'search', 'branch'));
    }

    public function syncLegacySp(NplCase $case)
    {
        // authorizeResource -> map ke update($case) (karena ini proses update data)
        $this->authorize('update', $case);

        SyncLegacySpJob::dispatch($case->id)->onQueue('crms');

        return back()->with('status', 'Sync Legacy SP diproses (queue).');
    }

    /**
     * DETAIL CASE
     */
    public function show(NplCase $case)
    {
        $case->loadMissing([
            'loanAccount',
            'legalCase',
            'latestLegalProposal.proposer', 
        ]);

        $case->load([
            'actions' => fn ($q) => $q
                ->with('proofs') // ✅ tambahin ini
                ->orderBy('action_at','desc')
                ->orderByDesc('id'),
        ]);

        // ===== last legal action + checklist =====
        $action = null;
        $checklist = collect();

        if ($case->legalCase?->id) {
            $action = \App\Models\LegalAction::query()
                ->where('legal_case_id', $case->legalCase->id)
                ->latest('id')
                ->first();

            if ($action) {
                $checklist = LegalAdminChecklist::query()
                    ->where('legal_action_id', $action->id)
                    ->orderBy('sort_order')
                    ->get();
            }
        }

        return view('cases.show', compact('case', 'action', 'checklist'));
    }

    /**
     * STORE ACTION + HOOK SCHEDULER
     */
    public function storeAction(Request $request, NplCase $case, CaseScheduler $scheduler)
    {
        $data = $request->validate([
            'ao_agenda_id'    => ['nullable', 'integer'],

            'action_at'       => ['nullable', 'date'],
            'action_type'     => ['required', 'string', 'max:50'],
            'description'     => ['nullable', 'string', 'max:5000'],
            'result'          => ['nullable', 'string', 'max:100'],
            'next_action'     => ['nullable', 'string', 'max:255'],
            'next_action_due' => ['nullable', 'date'],

            // legacy naming
            'evidences'       => ['nullable','array','max:3'],
            'evidences.*'     => ['file','mimes:jpg,jpeg,png,pdf','max:2048'],
            'evidence_note'   => ['nullable','string','max:500'],

            // naming baru
            'proofs'          => ['nullable', 'array', 'max:5'],
            'proofs.*'        => ['file', 'max:4096', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf'],
        ]);

        $userId = (int) $request->user()->id;

        $typeRaw = (string) $data['action_type'];
        $type    = strtolower(trim($typeRaw));

        $actionAt = !empty($data['action_at'])
            ? Carbon::parse($data['action_at'])
            : now();

        // ===== agenda binding (optional) =====
        $agenda = null;
        if (!empty($data['ao_agenda_id'])) {
            $agenda = AoAgenda::query()
                ->where('id', $data['ao_agenda_id'])
                ->where('npl_case_id', $case->id)
                ->first();

            if (!$agenda) abort(422, 'Agenda tidak valid untuk kasus ini.');
            $this->authorize('update', $agenda);
        }

        // ===== visit quickstart =====
        if (in_array($type, ['kunjungan', 'visit'], true)) {

            if ($agenda) {
                DB::transaction(function () use ($agenda, $userId) {
                    if (!in_array($agenda->status, ['done', 'cancelled'], true)) {
                        $agenda->status = 'in_progress';
                        $agenda->started_at ??= now();
                        $agenda->started_by ??= $userId;
                        $agenda->updated_by = $userId;
                        $agenda->save();
                    }
                });
            }

            $qs = http_build_query([
                'agenda' => $agenda?->id,
                'at'     => $actionAt->format('Y-m-d H:i:s'),
            ]);

            return redirect()->to(route('cases.visits.quickStart', $case) . ($qs ? "?{$qs}" : ""));
        }

        // ===== create CaseAction (1x) =====
        $createdAction = null;

        DB::transaction(function () use (&$createdAction, $case, $agenda, $data, $userId, $actionAt, $typeRaw) {

            if ($agenda && !in_array($agenda->status, ['done', 'cancelled'], true)) {
                if ($agenda->status !== 'in_progress') {
                    $agenda->status = 'in_progress';
                }
                $agenda->started_at ??= now();
                $agenda->started_by ??= $userId;
                $agenda->updated_by = $userId;
                $agenda->save();
            }

            $createdAction = CaseAction::create([
                'npl_case_id'     => $case->id,
                'ao_agenda_id'    => $agenda?->id,
                'user_id'         => $userId,
                'action_at'       => $actionAt,
                'action_type'     => $typeRaw,
                'description'     => $data['description'] ?? null,
                'result'          => $data['result'] ?? null,
                'next_action'     => $data['next_action'] ?? null,
                'next_action_due' => $data['next_action_due'] ?? null,
            ]);

            // auto-close agenda WA
            if ($agenda) {
                $agendaType = strtolower(trim((string) $agenda->agenda_type));
                if ($agendaType === 'wa' && !in_array($agenda->status, ['done', 'cancelled'], true)) {
                    $agenda->status       = 'done';
                    $agenda->completed_at = now();
                    $agenda->completed_by = $userId;
                    $agenda->updated_by   = $userId;
                    $agenda->save();
                }
            }
        });

        if (!$createdAction) abort(500, 'Gagal membuat log tindakan.');

        // =========================================================
        // Upload bukti TANPA SYMLINK => public/uploads/...
        // =========================================================
        $incomingFiles = [];

        if ($request->hasFile('proofs')) {
            $incomingFiles = array_merge($incomingFiles, (array) $request->file('proofs'));
        }
        if ($request->hasFile('evidences')) {
            $incomingFiles = array_merge($incomingFiles, (array) $request->file('evidences'));
        }

        if (!empty($incomingFiles)) {
            $baseDir = public_path("uploads/case-actions/{$case->id}/{$createdAction->id}");

            if (!is_dir($baseDir)) {
                if (!mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
                    abort(500, "Gagal membuat folder upload: {$baseDir}");
                }
            }

            $note = $data['evidence_note'] ?? null;

            foreach ($incomingFiles as $file) {
                if (!$file || !$file->isValid()) continue;

                $ext = strtolower($file->getClientOriginalExtension() ?: ($file->extension() ?: 'bin'));

                $orig = (string) $file->getClientOriginalName();
                $safeOrig = preg_replace('/[^a-zA-Z0-9\.\-\_\s]/', '', $orig);
                $safeOrig = trim($safeOrig) ?: ("proof." . $ext);

                $filename = now()->format('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

                // ambil info sebelum move
                $mime = null;
                $size = null;
                try { $mime = $file->getMimeType(); } catch (\Throwable $e) {}
                try { $size = $file->getSize(); } catch (\Throwable $e) {}

                // move (no symlink)
                try {
                    $file->move($baseDir, $filename);
                } catch (\Throwable $e) {
                    // skip file kalau gagal move
                    continue;
                }

                $relativePath = "uploads/case-actions/{$case->id}/{$createdAction->id}/{$filename}";

                // fallback: size dari file tujuan
                $destFull = $baseDir . DIRECTORY_SEPARATOR . $filename;
                if (!$size && is_file($destFull)) {
                    $size = @filesize($destFull) ?: null;
                }

                if (method_exists($createdAction, 'proofs')) {
                    $createdAction->proofs()->create([
                        'file_path'     => $relativePath,
                        'original_name' => $safeOrig,
                        'mime'          => $mime,
                        'size'          => $size,
                        'uploaded_by'   => $userId,
                        'note'          => $note,
                    ]);
                } else {
                    $meta = $createdAction->meta ?? [];
                    if (is_string($meta)) $meta = json_decode($meta, true);
                    if (!is_array($meta)) $meta = [];

                    $meta['proofs'] ??= [];
                    $meta['proofs'][] = [
                        'path' => $relativePath,
                        'name' => $safeOrig,
                        'mime' => $mime,
                        'size' => $size,
                        'note' => $note,
                    ];

                    $createdAction->meta = $meta;
                    $createdAction->save();
                }
            }
        }

        // ===== mark schedule done =====
        $scheduleType = match (true) {
            in_array($type, ['whatsapp', 'wa'], true) => 'wa',
            in_array($type, ['telpon', 'telepon', 'call'], true) => 'call',
            in_array($type, ['negosiasi'], true) => 'negosiasi',
            in_array($type, ['sp1','sp2','sp3','spt','spjad'], true) => $type,
            default => null,
        };

        $pendingScheduleQ = $case->schedules()
            ->where('status', 'pending')
            ->orderBy('scheduled_at');

        if ($scheduleType) $pendingScheduleQ->where('type', $scheduleType);

        $pendingSchedule = $pendingScheduleQ->first();
        if ($pendingSchedule) {
            $pendingSchedule->status       = 'done';
            $pendingSchedule->completed_at = now();
            $pendingSchedule->save();
        }

        $scheduler->generateNextAfterAction($case);

        $base = route('cases.show', $case);
        if ($agenda) {
            $base .= '?' . http_build_query([
                'tab'    => 'persuasif',
                'agenda' => $agenda->id,
            ]);
        }

        return redirect($base)->with('status', 'Log progres berhasil ditambahkan.');
    }

    /**
     * LIST OVERDUE (GLOBAL)
     */
    public function overdue(Request $request)
    {
        $this->authorize('viewAny', NplCase::class);

        $today = now()->toDateString();

        $cases = NplCase::query()
            ->with(['loanAccount', 'latestDueAction'])
            ->visibleFor(auth()->user()) // ✅ pagar akses: staff hanya miliknya
            ->whereNull('closed_at')
            ->whereHas('latestDueAction', function ($q) use ($today) {
                $q->whereDate('next_action_due', '<', $today);
            })
            ->orderByDesc('priority')
            ->orderBy('opened_at', 'asc')
            ->paginate(20)
            ->withQueryString();

        return view('cases.overdue', compact('cases'));
    }


    /**
     * FORM SP (MANUAL) — kalau masih dipakai
     */
    public function showSpForm(NplCase $case, string $type)
    {
        // authorizeResource map ke view($case)
        // $this->authorize('view', $case);

        $type = strtolower(trim($type));
        $valid = ['sp1', 'sp2', 'sp3', 'spt', 'spjad'];
        abort_unless(in_array($type, $valid, true), 404);

        $titles = [
            'sp1'   => 'Surat Peringatan 1 (SP1)',
            'sp2'   => 'Surat Peringatan 2 (SP2)',
            'sp3'   => 'Surat Peringatan 3 (SP3)',
            'spt'   => 'Surat Peringatan Terakhir (SPT)',
            'spjad' => 'SPJAD – Pemberitahuan Jaminan Akan Dilelang',
        ];

        $schedule = $case->schedules()
            ->where('type', $type)
            ->where('status', 'pending')
            ->first();

        return view('cases.sp-form', [
            'case'     => $case,
            'loan'     => $case->loanAccount,
            'type'     => $type,
            'title'    => $titles[$type],
            'schedule' => $schedule,
        ]);
    }

    /**
     * STORE SP (MANUAL) — kalau masih dipakai
     */
    public function storeSp(Request $request, NplCase $case, string $type, CaseScheduler $scheduler)
    {
        // authorizeResource map ke update($case) (karena ini perubahan data)
        // $this->authorize('update', $case);

        $type = strtolower(trim($type));
        $valid = ['sp1', 'sp2', 'sp3', 'spt', 'spjad'];
        abort_unless(in_array($type, $valid, true), 404);

        $data = $request->validate([
            'sent_at'     => ['required', 'date'],
            'method'      => ['required', 'string', 'max:50'],
            'receiver'    => ['nullable', 'string', 'max:100'],
            'notes'       => ['nullable', 'string', 'max:5000'],
            'attachment'  => ['nullable', 'file', 'max:2048'],
        ]);

        // simpan file jika ada
        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('sp_letters', 'public');
        }

        // simpan action SP
        $case->actions()->create([
            'action_type' => $type,
            'action_at'   => Carbon::parse($data['sent_at']),
            'description' => $data['notes'] ?? null,
            'result'      => $data['method'] . ($data['receiver'] ? ' (diterima oleh ' . $data['receiver'] . ')' : ''),
            'user_id'     => $request->user()->id,
            'attachment'  => $attachmentPath,
        ]);

        // tutup schedule pending untuk type ini
        $case->schedules()
            ->where('type', $type)
            ->where('status', 'pending')
            ->update([
                'status' => 'done',
                'completed_at' => now(),
            ]);

        // generate schedule berikutnya
        $scheduler->generateNextAfterAction($case);

        return redirect()
            ->route('cases.show', $case)
            ->with('status', strtoupper($type) . ' berhasil dicatat.');
    }
}
