<?php

namespace App\Http\Controllers;

use App\Models\ShmCheckRequest;
use App\Models\ShmCheckRequestFile;
use App\Models\ShmCheckRequestLog;
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

        $q = ShmCheckRequest::query()
            ->with(['requester'])
            ->visibleFor($user);

        // scope user non-SAD
        $rv = strtoupper(trim($user->roleValue() ?? ''));

        // AO/RO/SO/BE/FE hanya lihat milik sendiri
        if (!in_array($rv, ['KSA', 'KBO', 'SAD'], true)) {
            $q->where('requested_by', $user->id);
        }

        // optional filter status
        if ($request->filled('status') && $request->status !== 'ALL') {
            $q->where('status', $request->status);
        }

        $rows = $q->latest()->paginate(20)->withQueryString();

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

        return view('shm.index', compact('rows', 'statusOptions'));
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
            'certificate_no' => ['nullable', 'string', 'max:100'],
            'notary_name' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'ktp_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'shm_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
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
                'notary_name' => $data['notary_name'] ?? null, // optional (AO boleh isi, tapi final ditetapkan KSA/KBO)

                'status' => ShmCheckRequest::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            // store files
            $this->storeFile($req, 'ktp', request()->file('ktp_file'));
            $this->storeFile($req, 'shm', request()->file('shm_file'));

            $this->log($req, 'submitted', null, ShmCheckRequest::STATUS_SUBMITTED, 'Pengajuan dibuat');

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

        // map file groups
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
                'notary_name'       => $data['notary_name'], // ✅ ditetapkan final oleh KSA/KBO/SAD
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

        // ✅ sesuai flow: upload SP/SK saat sudah SENT_TO_NOTARY
        abort_unless($req->status === ShmCheckRequest::STATUS_SENT_TO_NOTARY, 422);

        $data = $request->validate([
            'sp_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'sk_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($req, $data) {
            $from = $req->status;

            $this->storeFile($req, 'sp', request()->file('sp_file'), $data['notes'] ?? null);
            $this->storeFile($req, 'sk', request()->file('sk_file'), $data['notes'] ?? null);

            $req->update([
                'status' => ShmCheckRequest::STATUS_SP_SK_UPLOADED,
                'sp_sk_uploaded_at' => now(),
            ]);

            $this->log($req, 'upload_sp_sk', $from, $req->status, 'KSA/KBO upload SP & SK dari notaris');

            return back()->with('status', 'SP & SK berhasil diupload. Menunggu tanda tangan debitur oleh AO.');
        });
    }

    public function markSentToBpn(ShmCheckRequest $req)
    {
        $this->authorize('sadAction', ShmCheckRequest::class);
        $this->authorize('view', $req);

        // ✅ sesuai koreksi: baru boleh ke BPN setelah AO menyerahkan fisik (HANDED_TO_SAD)
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

        // ✅ upload hasil hanya saat sudah SENT_TO_BPN
        abort_unless($req->status === ShmCheckRequest::STATUS_SENT_TO_BPN, 422);

        $data = $request->validate([
            'result_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($req, $data) {
            $from = $req->status;

            $this->storeFile($req, 'result', request()->file('result_file'), $data['notes'] ?? null);

            // ✅ sesuai koreksi: setelah hasil diupload -> CLOSED
            $req->update([
                'status' => ShmCheckRequest::STATUS_CLOSED,
                'result_uploaded_at' => now(),
                // 'closed_at' => now(), // aktifkan kalau kolom ada
            ]);

            $this->log($req, 'upload_result', $from, $req->status, 'KSA/KBO upload hasil cek SHM (pengajuan ditutup)');

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

        // ✅ AO upload signed hanya setelah SP_SK_UPLOADED
        abort_unless($req->status === ShmCheckRequest::STATUS_SP_SK_UPLOADED, 422);

        $data = $request->validate([
            'signed_sp_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'signed_sk_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($req, $data) {
            $from = $req->status;

            $this->storeFile($req, 'signed_sp', request()->file('signed_sp_file'), $data['notes'] ?? null);
            $this->storeFile($req, 'signed_sk', request()->file('signed_sk_file'), $data['notes'] ?? null);

            $req->update([
                'status' => ShmCheckRequest::STATUS_SIGNED_UPLOADED,
                'signed_uploaded_at' => now(),
            ]);

            $this->log($req, 'upload_signed', $from, $req->status, 'AO upload SP & SK yang sudah ditandatangani');

            return back()->with('status', 'Dokumen bertandatangan berhasil diupload. Silakan serahkan fisik ke KSA/KBO.');
        });
    }

    public function markHandedToSad(ShmCheckRequest $req)
    {
        $this->authorize('aoSignedUpload', $req); // AO pemohon
        $this->authorize('view', $req);

        abort_unless($req->status === ShmCheckRequest::STATUS_SIGNED_UPLOADED, 422);

        return DB::transaction(function () use ($req) {
            $from = $req->status;

            $req->update([
                'status' => ShmCheckRequest::STATUS_HANDED_TO_SAD,
                'handed_to_sad_at' => now(), // kalau kolom belum ada, buat migration / hilangkan baris ini
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
