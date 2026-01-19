<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Models\LegalAction;
use App\Models\LegalDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LegalActionDocumentController extends Controller
{
    public function store(Request $request, LegalAction $action)
    {
        $this->authorize('update', $action);

        $validated = $request->validate([
            'doc_type' => ['required', 'string', 'max:50'],
            'title'    => ['required', 'string', 'max:255'],
            'file'     => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        /**
         * ✅ Standarisasi (aman tanpa symlink):
         * - simpan ke disk "local"
         * - path: legal/actions/{action_id}/xxx.ext
         */
        $disk = 'local';
        $dir  = "legal/actions/{$action->id}";

        // hash sha256 dari temporary upload (lebih reliable daripada disk->path())
        $sha = null;
        try {
            $tmp = $file->getRealPath();
            $sha = ($tmp && is_file($tmp)) ? hash_file('sha256', $tmp) : null;
        } catch (\Throwable $e) {
            $sha = null;
        }

        // simpan file
        $path = $file->store($dir, $disk);

        DB::transaction(function () use ($action, $validated, $file, $path, $sha, $disk) {
            LegalDocument::create([
                'legal_case_id'   => $action->legal_case_id,
                'legal_action_id' => $action->id,

                'doc_type' => $validated['doc_type'],
                'title'    => $validated['title'],

                // file metadata
                'file_path'   => $path,
                'file_name'   => $file->getClientOriginalName(),
                'mime_type'   => $file->getMimeType(),
                'file_size'   => $file->getSize() ?? null,
                'hash_sha256' => $sha,

                // audit
                'uploaded_by' => auth()->id(),
                'uploaded_at' => now(),

                // kalau tabel kamu punya kolom disk, simpan:
                // 'file_disk' => $disk,
            ]);
        });

        return redirect()
            ->to(route('legal-actions.show', $action) . '?tab=documents')
            ->with('success', 'Dokumen berhasil diupload.');
    }

    public function download(LegalAction $action, LegalDocument $doc)
    {
        $this->authorize('view', $action);
        abort_unless((int) $doc->legal_action_id === (int) $action->id, 404);

        $path = ltrim((string) $doc->file_path, '/');
        abort_if($path === '', 404);

        // ✅ 1) uploads/* = public_direct (tanpa symlink)
        if (str_starts_with($path, 'uploads/') && Storage::disk('public_direct')->exists($path)) {
            return Storage::disk('public_direct')->download($path, $doc->file_name);
        }

        // ✅ 2) local (standar baru)
        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->download($path, $doc->file_name);
        }

        // ✅ 3) fallback legacy: public
        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->download($path, $doc->file_name);
        }

        abort(404, 'File tidak ditemukan di storage.');
    }

    public function destroy(LegalAction $action, LegalDocument $doc)
    {
        $this->authorize('update', $action);
        abort_unless((int) $doc->legal_action_id === (int) $action->id, 404);

        $path = ltrim((string) $doc->file_path, '/');
        $disk = $this->resolveDiskByPath($path);

        DB::transaction(function () use ($doc, $path, $disk) {
            if ($path !== '' && $disk && Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
            $doc->delete();
        });

        return redirect()
            ->to(route('legal-actions.show', $action) . '?tab=documents')
            ->with('success', 'Dokumen berhasil dihapus.');
    }

    /**
     * Tentukan disk yang paling mungkin untuk file_path.
     * Prioritas:
     * 1) uploads/* => public_direct
     * 2) local
     * 3) public
     */
    private function resolveDiskByPath(string $path): ?string
    {
        $path = ltrim($path, '/');
        if ($path === '') return null;

        if (str_starts_with($path, 'uploads/')) return 'public_direct';

        // standar baru: local
        if (Storage::disk('local')->exists($path)) return 'local';

        // legacy: public
        if (Storage::disk('public')->exists($path)) return 'public';

        // fallback: local (lebih aman untuk standar baru)
        return 'local';
    }
}
