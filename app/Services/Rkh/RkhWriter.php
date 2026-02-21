<?php

namespace App\Services\Rkh;

use App\Models\RkhHeader;
use App\Models\RkhDetail;
use Illuminate\Support\Facades\DB;

class RkhWriter
{
    /**
     * Hitung total menit dari items (fallback kalau timeMeta tidak ada).
     * - Support jam "HH:MM" atau "HH:MM:SS"
     * - Skip item yang jamnya kosong / invalid / selesai <= mulai
     * - Tidak support cross-midnight (kalau ada, dianggap invalid & di-skip)
     */
    private function calcTotalMinutesFromItems(array $items): int
    {
        $totalSeconds = 0;

        foreach ($items as $row) {
            $start = trim((string)($row['jam_mulai'] ?? ''));
            $end   = trim((string)($row['jam_selesai'] ?? ''));

            if ($start === '' || $end === '') continue;

            // normalize ke HH:MM:SS
            if (strlen($start) === 5) $start .= ':00';
            if (strlen($end) === 5)   $end   .= ':00';

            $s = strtotime("1970-01-01 {$start}");
            $e = strtotime("1970-01-01 {$end}");

            if ($s === false || $e === false) continue;
            if ($e <= $s) continue;

            $totalSeconds += ($e - $s);
        }

        return (int) floor($totalSeconds / 60);
    }

    /**
     * Ambil total_minutes dari meta kalau ada,
     * kalau tidak ada -> hitung dari items.
     */
    private function resolveTotalMinutes(array $items, array $timeMeta = []): int
    {
        if (array_key_exists('total_minutes', $timeMeta) && is_numeric($timeMeta['total_minutes'])) {
            return max(0, (int)$timeMeta['total_minutes']);
        }

        return max(0, $this->calcTotalMinutesFromItems($items));
    }

    /**
     * Bersihkan detail lama + networkingnya (jaga-jaga kalau FK cascade belum ada).
     */
    private function wipeOldDetails(RkhHeader $header): void
    {
        $detailIds = $header->details()->pluck('id')->all();

        if (!empty($detailIds)) {
            // tabel relasi networking kamu: aku asumsi namanya rkh_networkings
            // kalau beda (misal rkh_detail_networkings), ganti nama tabelnya ya bro
            DB::table('rkh_networkings')->whereIn('rkh_detail_id', $detailIds)->delete();
        }

        $header->details()->delete();
    }

    public function store(int $userId, string $tanggalYmd, array $items, array $timeMeta = []): RkhHeader
    {
        return DB::transaction(function () use ($userId, $tanggalYmd, $items, $timeMeta) {

            // header: upsert per user+tanggal (biar "1 klik tambah tanggal" aman)
            $header = RkhHeader::firstOrCreate(
                ['user_id' => $userId, 'tanggal' => $tanggalYmd],
                ['status' => 'draft', 'total_jam' => 0]
            );

            // ✅ Proteksi: jangan replace kalau sudah submitted/approved
            if (in_array($header->status, ['submitted', 'approved'], true)) {
                throw new \RuntimeException("RKH tanggal {$tanggalYmd} sudah {$header->status}, tidak bisa di-replace.");
            }

            // bersihin detail lama (replace all)
            $this->wipeOldDetails($header);

            foreach ($items as $row) {
                // normalize account_no (optional)
                $accountNo = isset($row['account_no']) ? trim((string)$row['account_no']) : null;
                if ($accountNo === '') $accountNo = null;

                /** @var RkhDetail $detail */
                $detail = $header->details()->create([
                    'jam_mulai'       => $row['jam_mulai'],
                    'jam_selesai'     => $row['jam_selesai'],
                    'nasabah_id'      => $row['nasabah_id'] ?? null,
                    'account_no'      => $accountNo,
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

            // ✅ total jam: selalu dihitung (meta kalau ada, fallback dari items)
            $totalMinutes = $this->resolveTotalMinutes($items, $timeMeta);
            $header->total_jam = round($totalMinutes / 60, 2);
            $header->save();

            return $header->fresh(['details.networking']);
        });
    }

    public function update(RkhHeader $header, array $items, array $timeMeta = []): RkhHeader
    {
        return DB::transaction(function () use ($header, $items, $timeMeta) {

            // ✅ kunci hanya draft/rejected
            if (in_array($header->status, ['submitted', 'approved'], true)) {
                throw new \RuntimeException("RKH status {$header->status} tidak bisa diubah.");
            }

            $this->wipeOldDetails($header);

            foreach ($items as $row) {
                $accountNo = isset($row['account_no']) ? trim((string)$row['account_no']) : null;
                if ($accountNo === '') $accountNo = null;

                $detail = $header->details()->create([
                    'jam_mulai'       => $row['jam_mulai'],
                    'jam_selesai'     => $row['jam_selesai'],
                    'nasabah_id'      => $row['nasabah_id'] ?? null,
                    'account_no'      => $accountNo,
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

            // ✅ total jam: selalu dihitung
            $totalMinutes = $this->resolveTotalMinutes($items, $timeMeta);
            $header->total_jam = round($totalMinutes / 60, 2);
            $header->save();

            return $header->fresh(['details.networking']);
        });
    }

    public function submit(RkhHeader $header): RkhHeader
    {
        if ($header->details()->count() === 0) {
            throw new \RuntimeException('Tidak bisa submit: belum ada kegiatan.');
        }
        if (!in_array($header->status, ['draft', 'rejected'], true)) {
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