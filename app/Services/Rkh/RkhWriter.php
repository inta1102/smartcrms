<?php

namespace App\Services\Rkh;

use App\Models\RkhHeader;
use App\Models\RkhDetail;
use Illuminate\Support\Facades\DB;

class RkhWriter
{
    public function store(int $userId, string $tanggalYmd, array $items, array $timeMeta = []): RkhHeader
    {
        return DB::transaction(function () use ($userId, $tanggalYmd, $items, $timeMeta) {

            // header: upsert per user+tanggal (biar "1 klik tambah tanggal" aman)
            $header = RkhHeader::firstOrCreate(
                ['user_id' => $userId, 'tanggal' => $tanggalYmd],
                ['status' => 'draft', 'total_jam' => 0]
            );

            // bersihin detail lama kalau kamu ingin store sebagai "replace all"
            $header->details()->delete();

            foreach ($items as $row) {
                // normalize account_no (optional)
                $accountNo = isset($row['account_no']) ? trim((string)$row['account_no']) : null;
                if ($accountNo === '') $accountNo = null;

                /** @var RkhDetail $detail */
                $detail = $header->details()->create([
                    'jam_mulai'       => $row['jam_mulai'],
                    'jam_selesai'     => $row['jam_selesai'],
                    'nasabah_id'      => $row['nasabah_id'] ?? null,
                    'account_no'      => $accountNo,                 // ✅ tambahan
                    'nama_nasabah'    => $row['nama_nasabah'] ?? null,
                    'kolektibilitas'  => $row['kolektibilitas'] ?? null,
                    'jenis_kegiatan'  => $row['jenis_kegiatan'],
                    'tujuan_kegiatan' => $row['tujuan_kegiatan'],
                    'area'            => $row['area'] ?? null,
                    'catatan'         => $row['catatan'] ?? null,
                ]);

                // networking (wajib jika pengembangan_jaringan, sudah divalidasi request)
                if (($row['jenis_kegiatan'] ?? '') === 'pengembangan_jaringan') {
                    $net = (array)($row['networking'] ?? []);
                    $detail->networking()->create([
                        'nama_relasi'  => (string)($net['nama_relasi'] ?? ''),
                        'jenis_relasi' => (string)($net['jenis_relasi'] ?? 'lainnya'),
                        'potensi'      => $net['potensi'] ?? null,
                        'follow_up'    => $net['follow_up'] ?? null,
                    ]);
                }
            }

            // simpan total jam (kalau ada dari validator)
            if (isset($timeMeta['total_minutes'])) {
                $header->total_jam = round(((int)$timeMeta['total_minutes']) / 60, 2);
                $header->save();
            }

            return $header->fresh(['details.networking']);
        });
    }

    public function update(RkhHeader $header, array $items, array $timeMeta = []): RkhHeader
    {
        return DB::transaction(function () use ($header, $items, $timeMeta) {

            // optional: kunci hanya draft
            if (in_array($header->status, ['submitted','approved'], true)) {
                throw new \RuntimeException("RKH status {$header->status} tidak bisa diubah.");
            }

            $header->details()->delete();

            foreach ($items as $row) {
                // normalize account_no (optional)
                $accountNo = isset($row['account_no']) ? trim((string)$row['account_no']) : null;
                if ($accountNo === '') $accountNo = null;

                $detail = $header->details()->create([
                    'jam_mulai'       => $row['jam_mulai'],
                    'jam_selesai'     => $row['jam_selesai'],
                    'nasabah_id'      => $row['nasabah_id'] ?? null,
                    'account_no'      => $accountNo,                 // ✅ tambahan
                    'nama_nasabah'    => $row['nama_nasabah'] ?? null,
                    'kolektibilitas'  => $row['kolektibilitas'] ?? null,
                    'jenis_kegiatan'  => $row['jenis_kegiatan'],
                    'tujuan_kegiatan' => $row['tujuan_kegiatan'],
                    'area'            => $row['area'] ?? null,
                    'catatan'         => $row['catatan'] ?? null,
                ]);

                if (($row['jenis_kegiatan'] ?? '') === 'pengembangan_jaringan') {
                    $net = (array)($row['networking'] ?? []);
                    $detail->networking()->create([
                        'nama_relasi'  => (string)($net['nama_relasi'] ?? ''),
                        'jenis_relasi' => (string)($net['jenis_relasi'] ?? 'lainnya'),
                        'potensi'      => $net['potensi'] ?? null,
                        'follow_up'    => $net['follow_up'] ?? null,
                    ]);
                }
            }

            if (isset($timeMeta['total_minutes'])) {
                $header->total_jam = round(((int)$timeMeta['total_minutes']) / 60, 2);
                $header->save();
            }

            return $header->fresh(['details.networking']);
        });
    }

    public function submit(RkhHeader $header): RkhHeader
    {
        if ($header->details()->count() === 0) {
            throw new \RuntimeException('Tidak bisa submit: belum ada kegiatan.');
        }
        if ($header->status !== 'draft' && $header->status !== 'rejected') {
            throw new \RuntimeException("Tidak bisa submit dari status {$header->status}.");
        }

        $header->status = 'submitted';
        $header->approval_note = null;
        $header->approved_by = null;
        $header->approved_at = null;
        $header->save();

        return $header->fresh();
    }
}
