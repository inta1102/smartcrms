<?php

namespace App\Services\Rkh;

use App\Models\LkhReport;
use App\Models\RkhDetail;
use Illuminate\Support\Facades\DB;

class LkhWriter
{
    public function store(RkhDetail $detail, array $payload): LkhReport
    {
        return DB::transaction(function () use ($detail, $payload) {
            // pastikan belum ada
            if ($detail->lkh()->exists()) {
                throw new \RuntimeException('LKH untuk kegiatan ini sudah ada.');
            }

            $report = $detail->lkh()->create([
                'is_visited' => array_key_exists('is_visited', $payload) ? (bool)$payload['is_visited'] : true,
                'hasil_kunjungan' => $payload['hasil_kunjungan'] ?? null,
                'respon_nasabah' => $payload['respon_nasabah'] ?? null,
                'tindak_lanjut' => $payload['tindak_lanjut'] ?? null,
                'evidence_path' => $payload['evidence_path'] ?? null,
            ]);

            return $report->fresh(['detail.header']);
        });
    }

    public function update(LkhReport $report, array $payload): LkhReport
    {
        return DB::transaction(function () use ($report, $payload) {
            $report->update([
                'is_visited' => array_key_exists('is_visited', $payload) ? (bool)$payload['is_visited'] : $report->is_visited,
                'hasil_kunjungan' => $payload['hasil_kunjungan'] ?? $report->hasil_kunjungan,
                'respon_nasabah' => $payload['respon_nasabah'] ?? $report->respon_nasabah,
                'tindak_lanjut' => $payload['tindak_lanjut'] ?? $report->tindak_lanjut,
                'evidence_path' => $payload['evidence_path'] ?? $report->evidence_path,
            ]);

            return $report->fresh(['detail.header']);
        });
    }
}
