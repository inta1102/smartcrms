<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LegacySpClient
{
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $cfg = (array) config('services.legacy_sp', []);
        $this->baseUrl = rtrim((string)($cfg['base_url'] ?? ''), '/');
        $this->token   = (string)($cfg['token'] ?? '');

        if ($this->baseUrl === '' || $this->token === '') {
            throw new \RuntimeException('Legacy SP config missing: services.legacy_sp.base_url/token');
        }
    }

    /**
     * Ambil histori surat berdasarkan 1 no_rekening.
     * Legacy contract: GET /api/crms/letters?no_rekening=...&after_id=...
     */
    public function lettersByNoRekening(string $noRekening, ?int $afterId = null): array
    {
        // penting: legacy validasi "string", jadi jangan pernah kirim array
        $noRekening = (string) $noRekening;

        $params = ['no_rekening' => $noRekening];
        if (!is_null($afterId)) $params['after_id'] = $afterId;

        $res = Http::timeout(20)
            ->acceptJson()
            ->withToken($this->token)
            ->get($this->baseUrl . '/api/crms/letters', $params);

        if (!$res->successful()) {
            throw new \RuntimeException('Legacy SP letters error: HTTP '.$res->status().' '.$res->body());
        }

        return (array) $res->json();
    }

    /**
     * Ambil histori surat berdasarkan beberapa kandidat no_rekening (12/13 digit).
     * Karena legacy kamu sekarang hanya support string, strategi yang benar adalah LOOP.
     *
     * Return selalu bentuk: ['data' => [...]]
     */
    public function lettersByNoRekeningIn(array $noRekenings, ?int $afterId = null): array
    {
        // normalize: digit-only, unique, non-empty
        $noRekenings = array_values(array_filter(array_unique(array_map(function ($v) {
            $v = preg_replace('/\D+/', '', (string) $v);
            return $v !== '' ? $v : null;
        }, $noRekenings))));

        if (empty($noRekenings)) {
            return ['data' => []];
        }

        $merged = [];

        foreach ($noRekenings as $rek) {
            try {
                $one  = $this->lettersByNoRekening((string) $rek, $afterId);
                $rows = $one['data'] ?? $one ?? [];
                if (is_array($rows)) {
                    $merged = array_merge($merged, $rows);
                }
            } catch (\Throwable $e) {
                // jangan matiin sync 1 case gara-gara 1 kandidat gagal
                Log::warning('[LEGACY-HTTP] lettersByNoRekeningIn candidate failed', [
                    'candidate' => $rek,
                    'after_id'  => $afterId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        // dedupe by legacy_id/id
        $uniq = [];
        foreach ($merged as $r) {
            if (!is_array($r)) continue;
            $key = $r['legacy_id'] ?? $r['id'] ?? null;
            if ($key === null) continue;
            $uniq[(string) $key] = $r;
        }

        $out = array_values($uniq);

        // sort by legacy_id/id asc (biar incremental logic enak)
        usort($out, function ($a, $b) {
            $ka = (int)($a['legacy_id'] ?? $a['id'] ?? 0);
            $kb = (int)($b['legacy_id'] ?? $b['id'] ?? 0);
            return $ka <=> $kb;
        });

        return ['data' => $out];
    }

    /**
     * Ambil bukti tanda terima (stream bytes) dari legacy.
     * Endpoint legacy: GET /api/crms/letters/{id}/proof
     */
    public function proofStream(int $legacyId): \Illuminate\Http\Client\Response
    {
        $res = Http::timeout(30)
            ->withToken($this->token)
            ->get($this->baseUrl . '/api/crms/letters/' . $legacyId . '/proof');

        // 404 => bukti belum ada
        if ($res->status() === 404) {
            return $res;
        }

        if (!$res->successful()) {
            throw new \RuntimeException('Legacy SP proof error: HTTP '.$res->status().' '.$res->body());
        }

        return $res;
    }
}
