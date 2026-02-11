<?php

namespace App\Services\Rkh;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RkhMasterService
{
    /**
     * Ambil master jenis (list) dari DB
     * @return array<int, array{code:string,label:string}>
     */
    public function jenis(): array
    {
        return Cache::remember('rkh.master.jenis.v1', 600, function () {
            return DB::table('master_jenis_kegiatan')
                ->select(['code', 'label'])
                ->where('is_active', 1)
                ->orderBy('sort')
                ->orderBy('id')
                ->get()
                ->map(fn($r) => [
                    'code'  => (string)($r->code ?? ''),
                    'label' => (string)($r->label ?? $r->code ?? ''),
                ])
                ->filter(fn($x) => ($x['code'] ?? '') !== '')
                ->values()
                ->all();
        });
    }

    /**
     * Map tujuan by jenis dari DB
     * @return array<string, array<int, array{code:string,label:string}>>
     */
    public function tujuanByJenis(): array
    {
        return Cache::remember('rkh.master.tujuanByJenis.v1', 600, function () {
            $rows = DB::table('master_tujuan_kegiatan')
                ->select(['jenis_code', 'code', 'label'])
                ->where('is_active', 1)
                ->orderBy('jenis_code')
                ->orderBy('sort')
                ->orderBy('id')
                ->get();

            $out = [];
            foreach ($rows as $r) {
                $jenis = (string)($r->jenis_code ?? '');
                $code  = (string)($r->code ?? '');
                if ($jenis === '' || $code === '') continue;

                $out[$jenis] ??= [];
                $out[$jenis][] = [
                    'code'  => $code,
                    'label' => (string)($r->label ?? $code),
                ];
            }

            return $out;
        });
    }

    /**
     * Ambil list tujuan untuk 1 jenis_code
     * @return array<int, array{code:string,label:string}>
     */
    public function tujuanForJenis(string $jenisCode): array
    {
        $jenisCode = (string) $jenisCode;
        $map = $this->tujuanByJenis();
        $list = $map[$jenisCode] ?? [];
        return is_array($list) ? array_values($list) : [];
    }

    /**
     * Helper: label jenis dari code
     */
    public function jenisLabel(?string $code): string
    {
        $code = (string) $code;
        foreach ($this->jenis() as $it) {
            if (($it['code'] ?? '') === $code) return (string) ($it['label'] ?? $code);
        }
        return $code;
    }

    /**
     * Helper: label tujuan dari code (butuh jenis)
     */
    public function tujuanLabel(?string $jenisCode, ?string $tujuanCode): string
    {
        $jenisCode  = (string) $jenisCode;
        $tujuanCode = (string) $tujuanCode;

        foreach ($this->tujuanForJenis($jenisCode) as $it) {
            if (($it['code'] ?? '') === $tujuanCode) return (string) ($it['label'] ?? $tujuanCode);
        }
        return $tujuanCode;
    }

    /**
     * Ambil default tujuan pertama untuk sebuah jenis (kalau butuh fallback).
     */
    public function defaultTujuanCode(string $jenisCode): string
    {
        $list = $this->tujuanForJenis($jenisCode);
        if (!empty($list)) return (string)($list[0]['code'] ?? '');
        return '';
    }

    /**
     * (Optional) Validasi master: cocok untuk debug.
     */
    public function assertValid(): void
    {
        foreach ($this->jenis() as $j) {
            if (!isset($j['code'], $j['label'])) {
                throw new \RuntimeException('Invalid master_jenis_kegiatan format');
            }
        }

        foreach ($this->tujuanByJenis() as $jenisCode => $list) {
            if (!is_array($list)) {
                throw new \RuntimeException("Invalid master_tujuan_kegiatan for jenis=$jenisCode");
            }
            foreach ($list as $t) {
                if (!isset($t['code'], $t['label'])) {
                    throw new \RuntimeException("Invalid tujuan item for jenis=$jenisCode");
                }
            }
        }
    }
}
