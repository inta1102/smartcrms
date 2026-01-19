<?php

namespace App\Http\Controllers;

use App\Models\CaseAction;
use App\Models\NplCase;
use App\Services\LegacySpClient;
use Illuminate\Http\Request;

class LegacySpProofController extends Controller
{
    public function __construct(private LegacySpClient $legacy) {}

    public function show(Request $request, NplCase $case, CaseAction $action)
    {
        // ✅ 1 pintu akses: pastikan user berhak melihat case ini
        $this->authorize('view', $case);

        // pastikan action milik case
        abort_unless((int) $action->npl_case_id === (int) $case->id, 404);

        // hanya action legacy
        abort_unless(($action->source_system ?? '') === 'legacy_sp', 404);

        // legacy_id disimpan di source_ref_id
        $legacyId = (int) ($action->source_ref_id ?? 0);
        abort_if($legacyId <= 0, 404);

        // ambil proof dari legacy
        try {
            $res = $this->legacy->proofStream($legacyId);
        } catch (\Throwable $e) {
            abort(502, 'Gagal mengambil bukti dari sistem legacy. Silakan coba lagi.');
        }

        if ($res->status() === 404) {
            abort(404, 'Bukti tanda terima belum ada di legacy.');
        }

        if ($res->status() >= 400) {
            abort(502, 'Sistem legacy sedang bermasalah. Silakan coba lagi.');
        }

        $contentType = $res->header('Content-Type') ?: 'application/octet-stream';
        $normalized  = strtolower(trim(explode(';', $contentType)[0]));

        // whitelist content-type aman
        $allowed = [
            'image/jpeg', 'image/png', 'image/webp',
            'application/pdf',
        ];
        if (!in_array($normalized, $allowed, true)) {
            $normalized = 'application/octet-stream';
        }

        $ext = match ($normalized) {
            'application/pdf' => 'pdf',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'image/jpeg'      => 'jpg',
            default           => 'bin',
        };

        $filename = "bukti-legacy-{$legacyId}.{$ext}";

        return response($res->body(), 200, [
            'Content-Type'           => $normalized,
            'Content-Disposition'    => 'inline; filename="'.$filename.'"',
            'Cache-Control'          => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
