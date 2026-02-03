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

        // ✅ longgar: semua legacy_ dianggap legacy
        $src = (string) ($caseAction->source_system ?? '');
        abort_unless(Str::startsWith($src, 'legacy_'), 404);

        // meta bisa string / array
        $meta = $caseAction->meta;
        if (is_string($meta)) $meta = json_decode($meta, true);
        if (!is_array($meta)) $meta = [];

        // legacy id bisa dari source_ref_id atau meta
        $legacyId = (int) ($caseAction->source_ref_id ?? 0);
        if ($legacyId <= 0) {
            $legacyId = (int) ($meta['legacy_id'] ?? $meta['source_ref_id'] ?? 0);
        }
        abort_if($legacyId <= 0, 404);

        // Ambil proof dari legacy
        try {
            $resp = $this->client->getProof($legacyId);
        } catch (\Throwable $e) {
            abort(502, 'Gagal mengambil bukti dari sistem legacy. Silakan coba lagi.');
        }

        if ($resp->status() === 404) {
            abort(404, 'Bukti tanda terima belum tersedia di legacy.');
        }
        if ($resp->status() !== 200) {
            abort(502, 'Sistem legacy sedang bermasalah. Silakan coba lagi.');
        }

        $contentType = $resp->header('Content-Type') ?: 'application/octet-stream';
        $normalized  = strtolower(trim(explode(';', $contentType)[0]));

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

        return response($resp->body(), 200, [
            'Content-Type'           => $normalized,
            'Content-Disposition'    => 'inline; filename="'.$filename.'"',
            'Cache-Control'          => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
