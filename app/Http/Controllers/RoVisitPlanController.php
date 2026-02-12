<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoVisitPlanController extends Controller
{
    public function toggle(Request $req)
    {
        $req->validate([
            'account_no' => ['required','string','max:255'],
            'checked'    => ['required','boolean'],
            'source'     => ['nullable','string','max:30'],
        ]);

        $u = $req->user();
        $accountNo = (string) $req->input('account_no');
        $checked   = (bool) $req->boolean('checked');
        $source    = $req->input('source');

        $today = now()->toDateString();

        // ambil data debitur dari loan_accounts
        $loan = DB::table('loan_accounts')
            ->select(['account_no','customer_name','kolek','dpd','outstanding'])
            ->where('account_no', $accountNo)
            ->first();

        if (!$loan) {
            return response()->json(['ok' => false, 'message' => 'Account tidak ditemukan'], 404);
        }

        DB::beginTransaction();
        try {
            if ($checked) {
                // 1) upsert ro_visits (plan)
                DB::table('ro_visits')->updateOrInsert(
                    [
                        'user_id'   => $u->id,
                        'account_no'=> $accountNo,
                        'visit_date'=> $today,
                    ],
                    [
                        'ao_code'    => $this->guessAoCodeByUser($u), // optional kalau kamu punya ao_code di user
                        'status'     => 'planned',
                        'source'     => $source,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                // 2) ensure rkh_headers (draft) untuk hari ini
                $rkhId = $this->ensureRkhHeader($u->id, $today);

                // 3) ensure rkh_details (plan row) ada
                $this->ensureRkhDetailPlanRow($rkhId, $loan);

            } else {
                // uncheck -> cancel ro_visits
                DB::table('ro_visits')
                    ->where('user_id', $u->id)
                    ->where('account_no', $accountNo)
                    ->where('visit_date', $today)
                    ->update([
                        'status' => 'cancelled',
                        'updated_at' => now(),
                    ]);

                // remove dari rkh_details biar RKH list bersih
                $this->removeRkhDetailByAccount($u->id, $today, $accountNo);
            }

            DB::commit();

            return response()->json([
                'ok' => true,
                'planned' => $checked,
                'plan_date' => $checked ? $today : null,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'ok' => false,
                'message' => 'Gagal toggle plan visit',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function ensureRkhHeader(int $userId, string $dateYmd): int
    {
        $row = DB::table('rkh_headers')
            ->select(['id'])
            ->where('user_id', $userId)
            ->where('tanggal', $dateYmd)
            ->first();

        if ($row) return (int) $row->id;

        return (int) DB::table('rkh_headers')->insertGetId([
            'user_id' => $userId,
            'tanggal' => $dateYmd,
            'total_jam' => 0,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureRkhDetailPlanRow(int $rkhId, object $loan): void
    {
        $exists = DB::table('rkh_details')
            ->where('rkh_id', $rkhId)
            ->where('account_no', $loan->account_no)
            ->exists();

        if ($exists) return;

        // ✅ karena field wajib NOT NULL, kita isi default placeholder dulu
        // nanti sore di IsiLKH user tinggal edit jam_mulai/jam_selesai/tujuan/catatan
        DB::table('rkh_details')->insert([
            'rkh_id' => $rkhId,

            'account_no' => $loan->account_no,
            'nama_nasabah' => $loan->customer_name ?? null,

            // kolektibilitas enum('L0','LT') -> isi dari kolek kalau kamu mau mapping
            'kolektibilitas' => $this->mapKolekToKolektibilitas($loan->kolek ?? null),

            // default time (placeholder)
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '09:00:00',

            // default wajib
            'jenis_kegiatan' => 'Visit Debitur',
            'tujuan_kegiatan' => 'Rencana visit (diisi detail sore hari)',

            'area' => null,
            'catatan' => null,

            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function removeRkhDetailByAccount(int $userId, string $dateYmd, string $accountNo): void
    {
        $hdr = DB::table('rkh_headers')
            ->select(['id'])
            ->where('user_id', $userId)
            ->where('tanggal', $dateYmd)
            ->first();

        if (!$hdr) return;

        DB::table('rkh_details')
            ->where('rkh_id', (int)$hdr->id)
            ->where('account_no', $accountNo)
            ->delete();
    }

    private function mapKolekToKolektibilitas($kolek): ?string
    {
        // optional: kamu bisa mapping sesuai rules bank
        // contoh: kolek 1/2 => L0, kolek >=3 => LT (silakan adjust)
        if ($kolek === null) return null;
        $k = (int) $kolek;
        return ($k >= 3) ? 'LT' : 'L0';
    }

    private function guessAoCodeByUser($u): ?string
    {
        // optional: kalau user punya ao_code
        return property_exists($u, 'ao_code') ? ($u->ao_code ?: null) : null;
    }
}
