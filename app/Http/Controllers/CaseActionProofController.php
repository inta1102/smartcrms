<?php

namespace App\Http\Controllers;

use App\Models\CaseAction;
use App\Models\NplCase;
use App\Services\LegacySpClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CaseActionProofController extends Controller
{
    public function __construct(private LegacySpClient $client) {}

    /**
     * GET /cases/{case}/actions/{action}/proof
     * Proxy bukti dari legacy agar user cukup akses CRMS.
     */
    public function show(Request $request, NplCase $case, CaseAction $action)
    {
        // ✅ 1 pintu akses: minimal user boleh lihat case (atau action)
        // Rekomendasi: cukup authorize case, karena action turunan dari case.
        $this->authorize('view', $case);

        // Pastikan action milik case yang sama
        if ((int) $action->npl_case_id !== (int) $case->id) {
            abort(404);
        }

        // Hanya untuk source legacy_sp + source_ref_id wajib ada & numeric
        if (($action->source_system ?? '') !== 'legacy_sp' || empty($action->source_ref_id)) {
            abort(404);
        }

        $legacyId = (int) $action->source_ref_id;
        if ($legacyId <= 0) {
            abort(404);
        }

        // Ambil stream dari legacy
        try {
            $res = $this->client->proofStream($legacyId);
        } catch (\Throwable $e) {
            // Jangan bocorkan detail internal legacy
            abort(502, 'Gagal mengambil bukti dari sistem legacy. Silakan coba lagi.');
        }

        if ($res->status() === 404) {
            abort(404, 'Bukti tanda terima belum tersedia di legacy.');
        }

        if ($res->status() >= 400) {
            abort(502, 'Sistem legacy sedang bermasalah. Silakan coba lagi.');
        }

        $contentType = $res->header('Content-Type') ?: 'application/octet-stream';

        // Whitelist ringan: hindari content-type aneh
        $allowed = [
            'image/jpeg', 'image/png', 'image/webp',
            'application/pdf',
        ];
        $normalized = strtolower(trim(explode(';', $contentType)[0]));
        if (!in_array($normalized, $allowed, true)) {
            $normalized = 'application/octet-stream';
        }

        // Nama file inline (biar enak kalau user download dari browser)
        $ext = match ($normalized) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };

        $safeName = 'proof_' . $case->id . '_' . $action->id . '.' . $ext;

        // ✅ Streaming response (lebih hemat memori daripada response($res->body()))
        return new StreamedResponse(function () use ($res) {
            echo $res->body(); // asumsi proofStream() mengembalikan response body lengkap
        }, 200, [
            'Content-Type'              => $normalized,
            'Content-Disposition'       => 'inline; filename="' . $safeName . '"',
            'Cache-Control'             => 'private, max-age=60',
            'X-Content-Type-Options'    => 'nosniff',
            // (opsional) kalau mau cegah di-embed:
            // 'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }
}
