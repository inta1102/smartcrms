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
        ];

        // ======================
        // ✅ COUNTS for Quick Chips
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
            'is_jogja' => ['nullable', 'boolean'], // ✅ baru
            'certificate_no' => ['nullable', 'string', 'max:100'],
            'notary_name' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:5000'],

            // ✅ PDF only
            'ktp_file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'shm_file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $user = auth()->user();

        return DB::transaction(function () use ($data, $user) {

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
            $this->storeFile($req, 'ktp', request()->file('ktp_file'));
            $this->storeFile($req, 'shm', request()->file('shm_file'));

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
            'requester:id,name',
            'files.uploader:id,name',
            'logs.actor:id,name',
        ]);

        $filesByType = $req->files->groupBy('type');

        return view('shm.show', compact('req', 'filesByType'));
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

            // ✅ SPDD optional (Jogja only, PDF)
            'spdd_file' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],

            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($req, $data, $request) {
            $from = $req->status;

            $this->storeFile($req, 'sp', $request->file('sp_file'), $data['notes'] ?? null);
            $this->storeFile($req, 'sk', $request->file('sk_file'), $data['notes'] ?? null);

            // ✅ SPDD disimpan hanya jika ada file & lokasi Jogja
            if ((bool)($req->is_jogja ?? false) && $request->hasFile('spdd_file')) {
                $this->storeFile($req, 'spdd', $request->file('spdd_file'), $data['notes'] ?? null);
            }

            $req->update([
                'status' => ShmCheckRequest::STATUS_SP_SK_UPLOADED,
                'sp_sk_uploaded_at' => now(),
            ]);

            $this->log($req, 'upload_sp_sk', $from, $req->status, 'KSA/KBO upload SP & SK dari notaris');

            // (opsional) WA ke pemohon saat SP/SK uploaded — sudah ada dispatcher, kalau mau nyalakan tinggal uncomment
            // DB::afterCommit(function () use ($req) {
            //     $this->dispatchWaSpSkUploadedToRequester($req);
            // });

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
            'result_file' => ['required', 'file', 'mimes:pdf', 'max:20480'], // ✅ PDF only
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($req, $data) {
            $from = $req->status;

            $this->storeFile($req, 'result', request()->file('result_file'), $data['notes'] ?? null);

            $req->update([
                'status' => ShmCheckRequest::STATUS_CLOSED,
                'result_uploaded_at' => now(),
            ]);

            $this->log($req, 'upload_result', $from, $req->status, 'KSA/KBO upload hasil cek SHM (pengajuan ditutup)');

            // ✅ WA: notify pemohon setelah hasil diupload (CLOSED)
            DB::afterCommit(function () use ($req) {
                $this->dispatchWaResultUploadedToRequester($req);
            });

            return back()->with('status', 'Hasil cek berhasil diupload. Pengajuan ditutup (closed).');
        });
    }

    // =======================
    // AO actions
    // =======================
    public function uploadSigned(Request $request, ShmCheckRequest $req)
    {
        $this->authorize('aoSignedUpload', $req);
        $this->authorize('view', $req);

        abort_unless($req->status === ShmCheckRequest::STATUS_SP_SK_UPLOADED, 422);

        $data = $request->validate([
            // ✅ request user: PDF only (signed juga PDF only biar konsisten)
            'signed_sp_file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'signed_sk_file' => ['required', 'file', 'mimes:pdf', 'max:20480'],

            // ✅ Signed SPDD optional (Jogja only, PDF only)
            'signed_spdd_file' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],

            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($req, $data, $request) {
            $from = $req->status;

            $this->storeFile($req, 'signed_sp', $request->file('signed_sp_file'), $data['notes'] ?? null);
            $this->storeFile($req, 'signed_sk', $request->file('signed_sk_file'), $data['notes'] ?? null);

            // ✅ Signed SPDD disimpan hanya jika Jogja & ada file
            if ((bool)($req->is_jogja ?? false) && $request->hasFile('signed_spdd_file')) {
                $this->storeFile($req, 'signed_spdd', $request->file('signed_spdd_file'), $data['notes'] ?? null);
            }

            $req->update([
                'status' => ShmCheckRequest::STATUS_SIGNED_UPLOADED,
                'signed_uploaded_at' => now(),
            ]);

            $this->log($req, 'upload_signed', $from, $req->status, 'AO upload dokumen bertandatangan (SP/SK/SPDD)');

            // ✅ WA: notify SAD/KSA setelah AO upload signed
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

            $this->log(
                $req,
                'handed_to_sad',
                $from,
                $req->status,
                'AO menyerahkan fisik SP & SK ke KSA/KBO'
            );

            return back()->with('status', 'Berkas fisik ditandai sudah diserahkan ke KSA/KBO.');
        });
    }

    public function downloadFile(ShmCheckRequestFile $file)
    {
        $req = $file->request()->firstOrFail();

        $this->authorize('view', $req);

        abort_if(
            $file->request_id !== $req->id,
            403,
            'Akses file tidak valid'
        );

        if (!Storage::disk('local')->exists($file->file_path)) {
            abort(404, 'File tidak ditemukan.');
        }

        return Storage::disk('local')->download(
            $file->file_path,
            $file->original_name
        );
    }

    // =======================
    // WA dispatchers (SHM flow)
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

    // ✅ NEW: AO upload signed -> notify SAD/KSA
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

    // ✅ NEW: hasil diupload/closed -> notify pemohon
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

    protected function sadUsersByBranch(?string $branchCode)
    {
        return User::query()
            ->select(['id', 'name', 'level', 'wa_number'])
            ->whereIn('level', ['KSA'])
            // ->whereIn('level', ['KSA', 'KBO', 'SAD'])
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
