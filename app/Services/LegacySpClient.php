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
        $noRekening = (string) $noRekening;

        $params = ['no_rekening' => $noRekening];
        if (!is_null($afterId)) {
            $params['after_id'] = $afterId;
        }

        $res = Http::timeout(20)
            ->retry(2, 300) // 2x retry, delay 300ms
            ->acceptJson()
            ->withToken($this->token)
            ->get($this->baseUrl . '/api/crms/letters', $params);

        if (!$res->successful()) {
            throw new \RuntimeException(
                'Legacy SP letters error: HTTP '.$res->status().' no_rekening='.$noRekening.' body='.$res->body()
            );
        }

        $json = $res->json();

        // normalize output jadi array
        if (!is_array($json)) {
            throw new \RuntimeException('Legacy SP letters error: invalid JSON shape for no_rekening='.$noRekening);
        }

        return $json;
    }

    /**
     * Ambil histori surat berdasarkan beberapa kandidat no_rekening.
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
                $one = $this->lettersByNoRekening((string) $rek, $afterId);

                // bentuk legacy yang diharapkan: ['data' => [...]]
                $rows = $one['data'] ?? null;

                // fallback: kalau legacy langsung balikin list
                if ($rows === null && $this->isListArray($one)) {
                    $rows = $one;
                }

                // pastikan rows benar-benar list (numerik)
                if (is_array($rows) && $this->isListArray($rows)) {
                    $merged = array_merge($merged, $rows);
                } else {
                    Log::warning('[LEGACY-HTTP] Unexpected letters payload shape', [
                        'candidate' => $rek,
                        'after_id'  => $afterId,
                        'keys'      => is_array($one) ? array_keys($one) : null,
                    ]);
                }
            } catch (\Throwable $e) {
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
            if ($key === null) {
                Log::warning('[LEGACY-HTTP] Letter row missing legacy_id/id', [
                    'row' => $r,
                ]);
                continue;
            }

            $uniq[(string) $key] = $r;
        }

        $out = array_values($uniq);

        // sort asc by legacy_id/id
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
            ->retry(2, 300)
            ->withToken($this->token)
            ->withOptions(['stream' => true]) // ✅ supaya nggak makan memory besar
            ->get($this->baseUrl . '/api/crms/letters/' . $legacyId . '/proof');

        if ($res->status() === 404) {
            return $res; // bukti belum ada
        }

        if (!$res->successful()) {
            throw new \RuntimeException('Legacy SP proof error: HTTP '.$res->status().' '.$res->body());
        }

        return $res;
    }

    /**
     * True kalau array adalah "list" (keys 0..n-1).
     */
    private function isListArray(array $arr): bool
    {
        if ($arr === []) return true;
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
