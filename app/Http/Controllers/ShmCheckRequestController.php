<?php

namespace App\Http\Controllers;

use App\Jobs\SendWaTemplateJob;
use App\Models\ShmCheckRequest;
use App\Models\ShmCheckRequestFile;
use App\Models\ShmCheckRequestLog;
use App\Models\User;
use App\Services\WhatsApp\ShmMessageFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ShmCheckRequestController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', ShmCheckRequest::class);

        $user = auth()->user();
        $rv = strtoupper(trim($user->roleValue() ?? ''));
        $isSad = in_array($rv, ['KSA', 'KBO', 'SAD'], true);

        // ======================
        // Base Query (list)
        // ======================
        $q = ShmCheckRequest::query()
            ->with(['requester'])
            ->visibleFor($user);

        // ✅ Default status untuk SAD jika tidak ada query status
        $status = $request->filled('status') ? $request->status : null;
        if (!$status && $isSad) {
            $status = ShmCheckRequest::STATUS_SUBMITTED;
        }

        if ($status && $status !== 'ALL') {
            $q->where('status', $status);
        }

        $rows = $q->latest()->paginate(20)->withQueryString();

        // ======================
        // Status Options
        // ======================
        $statusOptions = [
            'ALL',
            ShmCheckRequest::STATUS_SUBMITTED,
            ShmCheckRequest::STATUS_SENT_TO_NOTARY,
            ShmCheckRequest::STATUS_WAITING_SP_SK,
            ShmCheckRequest::STATUS_SP_SK_UPLOADED,
            ShmCheckRequest::STATUS_SIGNED_UPLOADED,
            ShmCheckRequest::STATUS_HANDED_TO_SAD,
            ShmCheckRequest::STATUS_SENT_TO_BPN,
            ShmCheckRequest::STATUS_RESULT_UPLOADED,
            ShmCheckRequest::STATUS_CLOSED,
            ShmCheckRequest::STATUS_REJECTED,

            // ✅ revisi
            ShmCheckRequest::STATUS_REVISION_REQUESTED,
            ShmCheckRequest::STATUS_REVISION_APPROVED,
        ];

        // ======================
        // ✅ COUNTS for Quick Chips (SAD)
        // ======================
        $counts = [];

        if ($isSad) {
            $baseCountQ = ShmCheckRequest::query()->visibleFor($user);

            $totalAll = (clone $baseCountQ)->count();

            $byStatus = (clone $baseCountQ)
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->toArray();

            $counts = [
                'ALL' => $totalAll,
                ShmCheckRequest::STATUS_SUBMITTED => (int)($byStatus[ShmCheckRequest::STATUS_SUBMITTED] ?? 0),
                ShmCheckRequest::STATUS_SENT_TO_NOTARY => (int)($byStatus[ShmCheckRequest::STATUS_SENT_TO_NOTARY] ?? 0),
                ShmCheckRequest::STATUS_SENT_TO_BPN => (int)($byStatus[ShmCheckRequest::STATUS_SENT_TO_BPN] ?? 0),
                ShmCheckRequest::STATUS_REVISION_REQUESTED => (int)($byStatus[ShmCheckRequest::STATUS_REVISION_REQUESTED] ?? 0),
            ];
        }

        return view('shm.index', compact('rows', 'statusOptions', 'status', 'isSad', 'counts'));
    }

    public function create()
    {
        $this->authorize('create', ShmCheckRequest::class);
        return view('shm.create');
    }

    public function store(Request $request)
    {
        $this->authorize('create', ShmCheckRequest::class);

        $data = $request->validate([
            'debtor_name' => ['required', 'string', 'max:191'],
            'debtor_phone' => ['nullable', 'string', 'max:50'],
            'collateral_address' => ['nullable', 'string', 'max:255'],
            'is_jogja' => ['nullable', 'boolean'],
            'certificate_no' => ['nullable', 'string', 'max:100'],
            'notary_name' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:5000'],

            // ✅ PDF only
            'ktp_file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'shm_file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $user = auth()->user();

        return DB::transaction(function () use ($data, $user, $request) {

            $requestNo = $this->generateRequestNo();

            $req = ShmCheckRequest::create([
                'request_no' => $requestNo,
                'requested_by' => $user->id,
                'branch_code' => $user->branch_code ?? null,
                'ao_code' => $user->ao_code ?? null,

                'debtor_name' => $data['debtor_name'],
                'debtor_phone' => $data['debtor_phone'] ?? null,
                'collateral_address' => $data['collateral_address'] ?? null,
                'certificate_no' => $data['certificate_no'] ?? null,
                'notary_name' => $data['notary_name'] ?? null,
                'is_jogja' => (bool)($data['is_jogja'] ?? false),

                'status' => ShmCheckRequest::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            // store files
            $this->storeFile($req, 'ktp', $request->file('ktp_file'));
            $this->storeFile($req, 'shm', $request->file('shm_file'));

            $this->log($req, 'submitted', null, ShmCheckRequest::STATUS_SUBMITTED, 'Pengajuan dibuat');

            // ✅ WA: setelah commit sukses -> notify KSA/SAD
            DB::afterCommit(function () use ($req) {
                $this->dispatchWaSubmitToSad($req);
            });

            return redirect()->route('shm.show', $req)->with('status', 'Pengajuan cek SHM berhasil dibuat.');
        });
    }

    public function show(ShmCheckRequest $req)
    {
        $this->authorize('view', $req);

        $req->load([
            'requester:id,name,wa_number',
            'files.uploader:id,name',
            'logs.actor:id,name',
        ]);

        $filesByType = $req->files->groupBy('type');

        return view('shm.show', compact('req', 'filesByType'));
    }

    public function replaceInitialFiles(Request $request, ShmCheckRequest $req)
    {
        // pemohon harus punya akses lihat & update (policy update kamu sudah: hanya SUBMITTED & pemohon)
        $this->authorize('view', $req);
        $this->authorize('update', $req);

        // ✅ kalau sudah di-lock oleh SAD/KSA (karena sudah pernah download), jangan replace langsung.
        // Opsi A: wajib lewat alur revisi (requestRevision -> approve -> uploadCorrected)
        if (!is_null($req->initial_files_locked_at)) {
            return back()->with('status', 'Dokumen awal sudah dikunci (sudah diunduh SAD/KSA). Silakan ajukan revisi melalui alur revisi.');
        }

        // kamu di store() wajib PDF, tapi di modal accept juga gambar.
        // biar fleksibel & aman, kita izinkan pdf/jpg/jpeg/png
        $data = $request->validate([
            'ktp_file'      => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
            'shm_file'      => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
            'replace_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($req, $request, $data) {
            $from = $req->status;

            // Simpan versi baru sebagai file baru (audit trail tetap ada).
            // Karena tabel file kamu belum punya flag "active/current", kita biarkan historinya ada.
            $note = $data['replace_notes'] ?? 'Perbaikan dokumen awal (KTP/SHM)';

            $this->storeFile($req, 'ktp', $request->file('ktp_file'), $note);
            $this->storeFile($req, 'shm', $request->file('shm_file'), $note);

            // status tetap SUBMITTED (tidak berubah), hanya dokumen yang diperbaiki
            $this->log(
                $req,
                'replace_initial_files',
                $from,
                $req->status,
                'Pemohon memperbaiki dokumen awal (KTP/SHM)',
                ['notes' => $note]
            );

            return back()->with('status', 'Dokumen KTP/SHM berhasil diperbaiki.');
        });
    }

    // =======================
    // KSA/KBO/SAD actions
    // =======================
    public function markSentToNotary(Request $request, ShmCheckRequest $req)
    {
        $this->authorize('sadAction', ShmCheckRequest::class);
        $this->authorize('view', $req);

        abort_unless($req->status === ShmCheckRequest::STATUS_SUBMITTED, 422);

        $data = $request->validate([
            'notary_name' => ['required', Rule::in(ShmCheckRequest::NOTARIES)],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($req, $data) {
            $from = $req->status;

            $req->update([
                'notary_name'       => $data['notary_name'],
                'status'            => ShmCheckRequest::STATUS_SENT_TO_NOTARY,
                'sent_to_notary_at' => now(),
            ]);

            $this->log(
                $req,
                'sent_to_notary',
                $from,
                $req->status,
                'KSA/KBO meneruskan berkas ke Notaris',
                ['notes' => $data['notes'] ?? null]
            );

            return back()->with('status', 'Status diupdate: diteruskan ke notaris.');
        });
    }

    public function uploadSpSk(Request $request, ShmCheckRequest $req)
    {
        $this->authorize('sadAction', ShmCheckRequest::class);
        $this->authorize('view', $req);

        abort_unless($req->status === ShmCheckRequest::STATUS_SENT_TO_NOTARY, 422);

        $data = $request->validate([
            'sp_file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'sk_file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'spdd_file' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($req, $data, $request) {
            $from = $req->status;

            $this->storeFile($req, 'sp', $request->file('sp_file'), $data['notes'] ?? null);
            $this->storeFile($req, 'sk', $request->file('sk_file'), $data['notes'] ?? null);

            // ✅ SPDD hanya jika Jogja & ada file
            if ((bool)($req->is_jogja ?? false) && $request->hasFile('spdd_file')) {
                $this->storeFile($req, 'spdd', $request->file('spdd_file'), $data['notes'] ?? null);
            }

            $req->update([
                'status' => ShmCheckRequest::STATUS_SP_SK_UPLOADED,
                'sp_sk_uploaded_at' => now(),
            ]);

            $this->log($req, 'upload_sp_sk', $from, $req->status, 'KSA/KBO upload SP & SK dari notaris');

            // ✅ WA notify AO (pemohon) bahwa SP/SK sudah siap
            DB::afterCommit(function () use ($req) {
                $this->dispatchWaSpSkUploadedToRequester($req);
            });

            return back()->with('status', 'SP & SK berhasil diupload. Menunggu tanda tangan debitur oleh AO.');
        });
    }

    public function markSentToBpn(ShmCheckRequest $req)
    {
        $this->authorize('sadAction', ShmCheckRequest::class);
        $this->authorize('view', $req);

        abort_unless($req->status === ShmCheckRequest::STATUS_HANDED_TO_SAD, 422);

        return DB::transaction(function () use ($req) {
            $from = $req->status;

            $req->update([
                'status' => ShmCheckRequest::STATUS_SENT_TO_BPN,
                'sent_to_bpn_at' => now(),
            ]);

            $this->log($req, 'sent_to_bpn', $from, $req->status, 'KSA/KBO menyerahkan berkas ke BPN');

            return back()->with('status', 'Status diupdate: proses BPN (menunggu hasil).');
        });
    }

    public function uploadResult(Request $request, ShmCheckRequest $req)
    {
        $this->authorize('sadAction', ShmCheckRequest::class);
        $this->authorize('view', $req);

        abort_unless($req->status === ShmCheckRequest::STATUS_SENT_TO_BPN, 422);

        $data = $request->validate([
            'result_file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($req, $data, $request) {
            $from = $req->status;

            $this->storeFile($req, 'result', $request->file('result_file'), $data['notes'] ?? null);

            $req->update([
                'status' => ShmCheckRequest::STATUS_CLOSED,
                'result_uploaded_at' => now(),
            ]);

            $this->log($req, 'upload_result', $from, $req->status, 'KSA/KBO upload hasil cek SHM (pengajuan ditutup)');

            // ✅ WA notify requester: hasil uploaded (kamu sudah test OK)
            DB::afterCommit(function () use ($req) {
                $this->dispatchWaResultUploadedToRequester($req);
            });

            return back()->with('status', 'Hasil cek berhasil diupload. Pengajuan ditutup (closed).');
        });
    }

    // =======================
    // ✅ REVISION FLOW (Opsi A)
    // =======================

    /**
     * AO ajukan revisi KTP/SHM (hanya jika sudah lock)
     * SUBMITTED + initial_files_locked_at != null => REVISION_REQUESTED
     */
    public function requestRevisionInitialDocs(Request $request, ShmCheckRequest $req)
    {
        $this->authorize('view', $req);
        $this->authorize('aoRevisionRequest', $req);

        abort_unless($req->status === ShmCheckRequest::STATUS_SUBMITTED, 422);
        abort_unless(!is_null($req->initial_files_locked_at), 422);

        $data = $request->validate([
            'revision_reason' => ['required', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($req, $data) {
            $from = $req->status;

            $req->update([
                'status' => ShmCheckRequest::STATUS_REVISION_REQUESTED,
                'revision_requested_at' => now(),
                'revision_requested_by' => auth()->id(),
                'revision_reason' => $data['revision_reason'],

                // reset approval (kalau pernah approve lalu minta lagi)
                'revision_approved_at' => null,
                'revision_approved_by' => null,
                'revision_approval_notes' => null,
            ]);

            $this->log($req, 'revision_requested', $from, $req->status, 'AO mengajukan revisi dokumen KTP/SHM', [
                'reason' => $data['revision_reason'],
            ]);

            DB::afterCommit(function () use ($req) {
                // ✅ fail-safe: jangan sampai request revisi gagal gara-gara WA
                try {
                    $this->dispatchWaRevisionRequestedToSad($req);
                } catch (\Throwable $e) {
                    \Log::warning('WA revision_requested failed', [
                        'request_id' => $req->id,
                        'request_no' => $req->request_no,
                        'err' => $e->getMessage(),
                    ]);
                }
            });

            return back()->with('status', 'Permintaan revisi dikirim ke SAD/KSA.');
        });
    }

    /**
     * SAD/KSA/KBO approve revisi => REVISION_APPROVED
     */
    public function approveRevisionInitialDocs(Request $request, ShmCheckRequest $req)
    {
        // ✅ authorize yang benar: ability approveRevisionInitialDocs butuh model $req
        $this->authorize('view', $req);
        $this->authorize('approveRevisionInitialDocs', $req);

        abort_unless($req->status === ShmCheckRequest::STATUS_REVISION_REQUESTED, 422);

        // ✅ sinkron dengan Blade: textarea name="approve_notes"
        $data = $request->validate([
            'approve_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($req, $data) {
            $from = $req->status;

            $req->update([
                'status' => ShmCheckRequest::STATUS_REVISION_APPROVED,
                'revision_approved_at' => now(),
                'revision_approved_by' => auth()->id(),
                'revision_approval_notes' => $data['approve_notes'] ?? null,
            ]);

            $this->log($req, 'revision_approved', $from, $req->status, 'SAD/KSA menyetujui revisi dokumen KTP/SHM', [
                'notes' => $data['approve_notes'] ?? null,
            ]);

            DB::afterCommit(function () use ($req) {
                // ✅ fail-safe juga
                try {
                    $this->dispatchWaRevisionApprovedToRequester($req);
                } catch (\Throwable $e) {
                    \Log::warning('WA revision_approved failed', [
                        'request_id' => $req->id,
                        'request_no' => $req->request_no,
                        'err' => $e->getMessage(),
                    ]);
                }
            });

            return back()->with('status', 'Revisi disetujui. AO bisa upload perbaikan KTP/SHM.');
        });
    }

/**
     * AO upload dokumen KTP/SHM pengganti setelah revisi di-approve
     * REVISION_APPROVED => kembali SUBMITTED (tetap locked)
     */
    public function uploadCorrectedInitialDocs(Request $request, ShmCheckRequest $req)
    {
        $this->authorize('view', $req);
        $this->authorize('aoRevisionUpload', $req);

        abort_unless($req->status === ShmCheckRequest::STATUS_REVISION_APPROVED, 422);

        $data = $request->validate([
            // ✅ wajib PDF
            'ktp_file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'shm_file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($req, $data, $request) {
            $from = $req->status;

            // simpan sebagai versi baru (type sama: ktp/shm) -> audit trail tetap ada
            $this->storeFile($req, 'ktp', $request->file('ktp_file'), $data['notes'] ?? 'Revisi KTP');
            $this->storeFile($req, 'shm', $request->file('shm_file'), $data['notes'] ?? 'Revisi SHM');

            $req->update([
                'status' => ShmCheckRequest::STATUS_SUBMITTED,
                // jangan buka lock; tetap locked
            ]);

            $this->log($req, 'revision_uploaded', $from, $req->status, 'AO upload perbaikan dokumen KTP/SHM (revisi)');

            DB::afterCommit(function () use ($req) {
                $this->dispatchWaRevisionUploadedToSad($req);
            });

            return back()->with('status', 'Perbaikan KTP/SHM berhasil diupload. Menunggu tindak lanjut SAD/KSA.');
        });
    }

    // =======================
    // AO actions (existing)
    // =======================
    public function uploadSigned(Request $request, ShmCheckRequest $req)
    {
        $this->authorize('aoSignedUpload', $req);
        $this->authorize('view', $req);

        abort_unless($req->status === ShmCheckRequest::STATUS_SP_SK_UPLOADED, 422);

        $data = $request->validate([
            'signed_sp_file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'signed_sk_file' => ['required', 'file', 'mimes:pdf', 'max:20480'],

            // ✅ signed SPDD optional (Jogja only)
            'signed_spdd_file' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],

            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($req, $data, $request) {
            $from = $req->status;

            $this->storeFile($req, 'signed_sp', $request->file('signed_sp_file'), $data['notes'] ?? null);
            $this->storeFile($req, 'signed_sk', $request->file('signed_sk_file'), $data['notes'] ?? null);

            if ((bool)($req->is_jogja ?? false) && $request->hasFile('signed_spdd_file')) {
                $this->storeFile($req, 'signed_spdd', $request->file('signed_spdd_file'), $data['notes'] ?? null);
            }

            $req->update([
                'status' => ShmCheckRequest::STATUS_SIGNED_UPLOADED,
                'signed_uploaded_at' => now(),
            ]);

            $this->log($req, 'upload_signed', $from, $req->status, 'AO upload SP & SK yang sudah ditandatangani');

            // ✅ WA ke SAD/KSA bahwa signed sudah diupload (opsional kalau mau)
            DB::afterCommit(function () use ($req) {
                $this->dispatchWaSignedUploadedToSad($req);
            });

            return back()->with('status', 'Dokumen bertandatangan berhasil diupload. Silakan serahkan fisik ke KSA/KBO.');
        });
    }

    public function markHandedToSad(ShmCheckRequest $req)
    {
        $this->authorize('aoSignedUpload', $req);
        $this->authorize('view', $req);

        abort_unless($req->status === ShmCheckRequest::STATUS_SIGNED_UPLOADED, 422);

        return DB::transaction(function () use ($req) {
            $from = $req->status;

            $req->update([
                'status' => ShmCheckRequest::STATUS_HANDED_TO_SAD,
                'handed_to_sad_at' => now(),
            ]);

            $this->log($req, 'handed_to_sad', $from, $req->status, 'AO menyerahkan fisik SP & SK ke KSA/KBO');

            return back()->with('status', 'Berkas fisik ditandai sudah diserahkan ke KSA/KBO.');
        });
    }

    // =======================
    // ✅ Download + LOCK logic
    // =======================
    public function downloadFile(ShmCheckRequestFile $file)
    {
        $req = $file->request()->firstOrFail();
        $this->authorize('view', $req);

        abort_if($file->request_id !== $req->id, 403, 'Akses file tidak valid');

        // ✅ saat SAD/KSA download KTP/SHM => lock
        $user = auth()->user();
        $rv = strtoupper(trim($user->roleValue() ?? ''));
        $isSad = in_array($rv, ['KSA','KBO','SAD'], true);

        if ($isSad && in_array($file->type, ['ktp','shm'], true)) {
            if (is_null($req->initial_files_locked_at)) {
                $req->forceFill([
                    'initial_files_locked_at' => now(),
                    'initial_files_locked_by' => $user->id,
                ])->save();

                $this->log($req, 'initial_files_locked', $req->status, $req->status, 'Dokumen awal (KTP/SHM) terkunci karena sudah diunduh SAD/KSA');
            }
        }

        if (!Storage::disk('local')->exists($file->file_path)) {
            abort(404, 'File tidak ditemukan.');
        }

        return Storage::disk('local')->download($file->file_path, $file->original_name);
    }

    // =======================
    // WA dispatchers (existing + tambahan revisi)
    // =======================
    protected function dispatchWaSubmitToSad(ShmCheckRequest $req): void
    {
        $tpl = (string) config('whatsapp.qontak.templates.ticket_notify_any');
        if ($tpl === '') return;

        $req->loadMissing(['requester:id,name']);

        $vars = ShmMessageFactory::buildSubmitToSadVars($req, [
            'audience' => 'SAD',
            'requester_name' => $req->requester?->name ?? 'Pemohon',
        ]);

        $meta = [
            'buttons' => [
                [
                    'type'  => 'URL',
                    'index' => '0',
                    'value' => ShmMessageFactory::shmButtonPath($req),
                ],
            ],
        ];

        $group = (string) config('whatsapp.recipients.shm_sad_group', '');
        if (trim($group) !== '') {
            SendWaTemplateJob::dispatch($group, $tpl, $vars, $meta)->onQueue('wa');
            return;
        }

        foreach ($this->sadUsersByBranch($req->branch_code) as $u) {
            $to = $this->userWaTo($u);
            if (!$to) continue;

            SendWaTemplateJob::dispatch($to, $tpl, $vars, $meta)->onQueue('wa');
        }
    }

    protected function dispatchWaSpSkUploadedToRequester(ShmCheckRequest $req): void
    {
        $tpl = (string) config('whatsapp.qontak.templates.ticket_notify_any');
        if ($tpl === '') return;

        $req->loadMissing(['requester:id,name,wa_number']);
        $requester = $req->requester;
        if (!$requester) return;

        $to = $this->userWaTo($requester);
        if (!$to) return;

        $vars = ShmMessageFactory::buildSpSkUploadedToRequesterVars($req, [
            'requester_name' => $requester->name ?? 'Pemohon',
        ]);

        $meta = [
            'buttons' => [
                [
                    'type'  => 'URL',
                    'index' => '0',
                    'value' => ShmMessageFactory::shmButtonPath($req),
                ],
            ],
        ];

        SendWaTemplateJob::dispatch($to, $tpl, $vars, $meta)->onQueue('wa');
    }

    // ✅ hasil uploaded -> requester (kamu sudah test OK)
    protected function dispatchWaResultUploadedToRequester(ShmCheckRequest $req): void
    {
        $tpl = (string) config('whatsapp.qontak.templates.ticket_notify_any');
        if ($tpl === '') return;

        $req->loadMissing(['requester:id,name,wa_number']);
        $requester = $req->requester;
        if (!$requester) return;

        $to = $this->userWaTo($requester);
        if (!$to) return;

        $vars = ShmMessageFactory::buildResultUploadedToRequesterVars($req, [
            'requester_name' => $requester->name ?? 'Pemohon',
        ]);

        $meta = [
            'buttons' => [
                [
                    'type'  => 'URL',
                    'index' => '0',
                    'value' => ShmMessageFactory::shmButtonPath($req),
                ],
            ],
        ];

        SendWaTemplateJob::dispatch($to, $tpl, $vars, $meta)->onQueue('wa');
    }

    // ✅ signed uploaded -> SAD (opsional)
    protected function dispatchWaSignedUploadedToSad(ShmCheckRequest $req): void
    {
        $tpl = (string) config('whatsapp.qontak.templates.ticket_notify_any');
        if ($tpl === '') return;

        $req->loadMissing(['requester:id,name']);

        $vars = ShmMessageFactory::buildSignedUploadedToSadVars($req, [
            'audience' => 'SAD',
            'requester_name' => $req->requester?->name ?? 'Pemohon',
        ]);

        $meta = [
            'buttons' => [
                [
                    'type'  => 'URL',
                    'index' => '0',
                    'value' => ShmMessageFactory::shmButtonPath($req),
                ],
            ],
        ];

        $group = (string) config('whatsapp.recipients.shm_sad_group', '');
        if (trim($group) !== '') {
            SendWaTemplateJob::dispatch($group, $tpl, $vars, $meta)->onQueue('wa');
            return;
        }

        foreach ($this->sadUsersByBranch($req->branch_code) as $u) {
            $to = $this->userWaTo($u);
            if (!$to) continue;

            SendWaTemplateJob::dispatch($to, $tpl, $vars, $meta)->onQueue('wa');
        }
    }

    // ✅ revisi requested -> SAD
    protected function dispatchWaRevisionRequestedToSad(ShmCheckRequest $req): void
    {
        $tpl = (string) config('whatsapp.qontak.templates.ticket_notify_any');
        if ($tpl === '') return;

        $req->loadMissing(['requester:id,name']);

        $vars = ShmMessageFactory::buildRevisionRequestedToSadVars($req, [
            'audience' => 'SAD',
            'requester_name' => $req->requester?->name ?? 'Pemohon',
        ]);

        $meta = [
            'buttons' => [
                [
                    'type'  => 'URL',
                    'index' => '0',
                    'value' => ShmMessageFactory::shmButtonPath($req),
                ],
            ],
        ];

        $group = (string) config('whatsapp.recipients.shm_sad_group', '');
        if (trim($group) !== '') {
            SendWaTemplateJob::dispatch($group, $tpl, $vars, $meta)->onQueue('wa');
            return;
        }

        foreach ($this->sadUsersByBranch($req->branch_code) as $u) {
            $to = $this->userWaTo($u);
            if (!$to) continue;

            SendWaTemplateJob::dispatch($to, $tpl, $vars, $meta)->onQueue('wa');
        }
    }

    // ✅ revisi approved -> AO
    protected function dispatchWaRevisionApprovedToRequester(ShmCheckRequest $req): void
    {
        $tpl = (string) config('whatsapp.qontak.templates.ticket_notify_any');
        if ($tpl === '') return;

        $req->loadMissing(['requester:id,name,wa_number']);
        $requester = $req->requester;
        if (!$requester) return;

        $to = $this->userWaTo($requester);
        if (!$to) return;

        $vars = ShmMessageFactory::buildRevisionApprovedToRequesterVars($req, [
            'requester_name' => $requester->name ?? 'Pemohon',
        ]);

        $meta = [
            'buttons' => [
                [
                    'type'  => 'URL',
                    'index' => '0',
                    'value' => ShmMessageFactory::shmButtonPath($req),
                ],
            ],
        ];

        SendWaTemplateJob::dispatch($to, $tpl, $vars, $meta)->onQueue('wa');
    }

    // ✅ revisi uploaded -> SAD (minta redownload)
    protected function dispatchWaRevisionUploadedToSad(ShmCheckRequest $req): void
    {
        $tpl = (string) config('whatsapp.qontak.templates.ticket_notify_any');
        if ($tpl === '') return;

        $req->loadMissing(['requester:id,name']);

        $vars = ShmMessageFactory::buildRevisionUploadedToSadVars($req, [
            'audience' => 'SAD',
            'requester_name' => $req->requester?->name ?? 'Pemohon',
        ]);

        $meta = [
            'buttons' => [
                [
                    'type'  => 'URL',
                    'index' => '0',
                    'value' => ShmMessageFactory::shmButtonPath($req),
                ],
            ],
        ];

        $group = (string) config('whatsapp.recipients.shm_sad_group', '');
        if (trim($group) !== '') {
            SendWaTemplateJob::dispatch($group, $tpl, $vars, $meta)->onQueue('wa');
            return;
        }

        foreach ($this->sadUsersByBranch($req->branch_code) as $u) {
            $to = $this->userWaTo($u);
            if (!$to) continue;

            SendWaTemplateJob::dispatch($to, $tpl, $vars, $meta)->onQueue('wa');
        }
    }

    protected function sadUsersByBranch(?string $branchCode)
    {
        return User::query()
            ->select(['id', 'name', 'level', 'wa_number'])
            ->whereIn('level', ['KSA'])
            ->get();
    }

    protected function userWaTo(User $u): ?string
    {
        $raw = $u->wa_number ?? null;
        if (!$raw) return null;

        $s = preg_replace('/\D+/', '', (string) $raw);
        if ($s === '') return null;

        if (str_starts_with($s, '0')) $s = '62' . substr($s, 1);
        return $s;
    }

    // =======================
    // Helpers
    // =======================
    protected function storeFile(ShmCheckRequest $req, string $type, $uploadedFile, ?string $notes = null): void
    {
        $dir = "shm_check/{$req->request_no}";
        $path = $uploadedFile->store($dir, 'local');

        ShmCheckRequestFile::create([
            'request_id' => $req->id,
            'type' => $type,
            'file_path' => $path,
            'original_name' => $uploadedFile->getClientOriginalName(),
            'uploaded_by' => auth()->id(),
            'uploaded_at' => now(),
            'notes' => $notes,
            'hash' => hash_file('sha256', $uploadedFile->getRealPath()),
            'size' => $uploadedFile->getSize(),
        ]);
    }

    protected function log(ShmCheckRequest $req, string $action, ?string $from, ?string $to, ?string $message = null, array $meta = []): void
    {
        ShmCheckRequestLog::create([
            'request_id' => $req->id,
            'actor_id' => auth()->id(),
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'message' => $message,
            'meta' => $meta ?: null,
        ]);
    }

    protected function generateRequestNo(): string
    {
        $prefix = 'SHM-' . now()->format('Ym') . '-';
        $last = ShmCheckRequest::where('request_no', 'like', $prefix . '%')
            ->orderBy('request_no', 'desc')
            ->value('request_no');

        $seq = 1;
        if ($last) {
            $lastSeq = (int)substr($last, -4);
            $seq = $lastSeq + 1;
        }
        return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }
}
