<?php

namespace App\Http\Controllers;

use App\Models\CaseAction;
use App\Models\NplCase;
use App\Services\LegacySpClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CaseLegacyProofController extends Controller
{
    public function __construct(private LegacySpClient $client) {}

    public function show(Request $request, NplCase $case, CaseAction $caseAction)
    {
        // ✅ 1 pintu akses
        $this->authorize('view', $case);

        // Pastikan action milik case yang dibuka
        abort_unless((int) $caseAction->npl_case_id === (int) $case->id, 404);

        // ✅ hanya action legacy_*
        $src = (string) ($caseAction->source_system ?? '');
        abort_unless($src !== '' && Str::startsWith($src, 'legacy_'), 404);

        // meta bisa string / array (safe decode)
        $meta = $caseAction->meta;
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
        } elseif (!is_array($meta)) {
            $meta = [];
        }

        // legacy id bisa dari source_ref_id atau meta
        $legacyId = (int) ($caseAction->source_ref_id ?? 0);
        if ($legacyId <= 0) {
            $legacyId = (int) ($meta['legacy_id'] ?? $meta['source_ref_id'] ?? 0);
        }

        // ✅ hardening: kalau legacyId tidak ada, jangan 404 mentah
        if ($legacyId <= 0) {
            return back()->with('status', 'Data legacy tidak tersedia untuk bukti ini.');
        }

        // Ambil proof dari legacy
        try {
            $resp = $this->client->getProof($legacyId); // alias ke proofStream()
        } catch (\Throwable $e) {
            \Log::error('[LEGACY-PROOF] fetch failed', [
                'case_id'     => (int) $case->id,
                'action_id'   => (int) $caseAction->id,
                'legacy_id'   => (int) $legacyId,
                'source_sys'  => $src,
                'error'       => $e->getMessage(),
                'class'       => get_class($e),
            ]);

            abort(502, 'Gagal menghubungi sistem legacy. Silakan coba lagi.');
        }

        // ✅ hardening: 404 = bukti belum tersedia (business case)
        if ($resp->status() === 404) {
            return back()->with('status', 'Bukti tanda terima belum tersedia di sistem legacy.');
        }

        // ✅ selain 200, anggap legacy error
        if (!$resp->successful()) {
            \Log::warning('[LEGACY-PROOF] legacy non-200', [
                'case_id'     => (int) $case->id,
                'action_id'   => (int) $caseAction->id,
                'legacy_id'   => (int) $legacyId,
                'status'      => $resp->status(),
                'contentType' => $resp->header('Content-Type'),
                'body_head'   => substr((string) $resp->body(), 0, 200),
            ]);

            abort(502, 'Sistem legacy sedang bermasalah. Silakan coba lagi.');
        }

        // Normalize & allowlist content types (security)
        $contentType = (string) ($resp->header('Content-Type') ?: 'application/octet-stream');
        $normalized  = strtolower(trim(explode(';', $contentType)[0]));

        $allowed = [
            'image/jpeg', 'image/png', 'image/webp',
            'application/pdf',
        ];

        if (!in_array($normalized, $allowed, true)) {
            \Log::warning('[LEGACY-PROOF] blocked content-type', [
                'legacy_id'    => (int) $legacyId,
                'content_type' => $contentType,
            ]);
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

        return response((string) $resp->body(), 200, [
            'Content-Type'           => $normalized,
            'Content-Disposition'    => 'inline; filename="' . $filename . '"',
            'Cache-Control'          => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
