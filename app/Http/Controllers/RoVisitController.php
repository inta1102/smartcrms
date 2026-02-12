<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RoVisitController extends Controller
{
    /**
     * Toggle plan visit hari ini (checkbox).
     * - checked=true  => upsert planned (user_id, account_no, visit_date=today)
     * - checked=false => delete jika status masih planned
     * Lock: kalau status sudah 'done' => tidak boleh dihapus lewat checkbox
     */
    public function toggle(Request $request)
    {
        $user = $request->user();
        abort_if(!$user, 401);

        $data = $request->validate([
            'account_no' => ['required', 'string', 'max:30'],
            'ao_code'    => ['nullable', 'string', 'max:6'],
            'checked'    => ['required'], // bisa boolean / "true"/"false"
            'source'     => ['nullable', 'string', 'max:30'],
        ]);

        $accountNo = trim((string) ($data['account_no'] ?? ''));
        abort_if($accountNo === '', 422, 'account_no empty');

        $aoCode = isset($data['ao_code']) ? trim((string) $data['ao_code']) : null;
        if ($aoCode !== null && $aoCode !== '') {
            $aoCode = str_pad($aoCode, 6, '0', STR_PAD_LEFT);
        } else {
            $aoCode = null;
        }

        $checked = filter_var($data['checked'], FILTER_VALIDATE_BOOLEAN);

        $source = trim((string) ($data['source'] ?? 'dashboard'));
        if ($source === '') $source = 'dashboard';

        $today = Carbon::today()->toDateString();
        $now   = now();

        $row = DB::table('ro_visits')
            ->where('user_id', (int) $user->id)
            ->where('account_no', $accountNo)
            ->where('visit_date', $today)
            ->first();

        // kalau sudah done => lock
        if ($row && (string)($row->status ?? '') === 'done') {
            return response()->json([
                'ok'         => true,
                'locked'     => true,
                'checked'    => true,
                'status'     => 'done',
                'visit_date' => $today,
                'plan_date'  => $today,
                'message'    => 'Sudah DONE. Tidak bisa diubah via checklist.',
            ]);
        }

        if ($checked) {
            if ($row) {
                DB::table('ro_visits')
                    ->where('id', $row->id)
                    ->update([
                        'ao_code'    => $aoCode ?? $row->ao_code,
                        'status'     => 'planned',
                        'source'     => $source ?: ($row->source ?? null),
                        'updated_at' => $now,
                    ]);
            } else {
                DB::table('ro_visits')->insert([
                    'user_id'    => (int) $user->id,
                    'account_no' => $accountNo,
                    'ao_code'    => $aoCode,
                    'visit_date' => $today,
                    'status'     => 'planned',
                    'source'     => $source,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return response()->json([
                'ok'         => true,
                'locked'     => false,
                'checked'    => true,
                'status'     => 'planned',
                'visit_date' => $today,
                'plan_date'  => $today,
            ]);
        }

        // unchecked => hapus (row done sudah di-return di atas)
        if ($row) {
            DB::table('ro_visits')->where('id', $row->id)->delete();
        }

        return response()->json([
            'ok'         => true,
            'locked'     => false,
            'checked'    => false,
            'status'     => null,
            'visit_date' => $today,
            'plan_date'  => null,
        ]);
    }

    /**
     * Form input LKH + upload foto (mobile).
     * Route contoh: GET /ro-visits/visit?account_no=...&back=...
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_if(!$user, 401);

        $accountNo = trim((string)$request->query('account_no', ''));
        abort_if($accountNo === '', 422, 'account_no required');

        $today = now()->toDateString();

        $visit = DB::table('ro_visits')
            ->where('user_id', (int)$user->id)
            ->where('account_no', $accountNo)
            ->where('visit_date', $today)
            ->first();

        abort_if(!$visit, 404, 'Visit belum di-plan.');

        // ✅ Back URL (prioritas: query back, fallback: previous, terakhir: /kpi/ro/os-daily)
        $back = (string) $request->query('back', '');
        if ($back === '') $back = url()->previous();
        if ($back === '') $back = route('kpi.ro.os-daily'); // kalau route beda, ganti sekali di sini

        // ✅ ambil info debitur dari loan_accounts posisi terakhir
        $deb = $this->getLoanAccountLatest($accountNo);

        // ✅ photos (kalau tabel ro_visit_photos ada)
        $photos = collect();
        if (Schema::hasTable('ro_visit_photos')) {
            $photos = DB::table('ro_visit_photos')
                ->where('ro_visit_id', (int)$visit->id)
                ->orderBy('id', 'asc')
                ->get();
        }

        return view('ro_visits.form', [
            'visit'  => $visit,
            'deb'    => $deb,
            'photos' => $photos,
            'back'   => $back,
        ]);
    }

    /**
     * Simpan LKH + foto, set DONE.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_if(!$user, 401);

        $data = $request->validate([
            'visit_id'   => ['required','integer'],
            'account_no' => ['required','string','max:30'],

            'lat'      => ['nullable','string','max:30'],
            'lng'      => ['nullable','string','max:30'],

            'lkh_note' => ['required','string','min:10'],

            'photos'   => ['nullable','array','max:6'],
            'photos.*' => ['file','mimes:jpg,jpeg,png','max:5120'], // 5MB

            'back'     => ['nullable','string','max:2048'],
        ]);

        $visit = DB::table('ro_visits')
            ->where('id', (int)$data['visit_id'])
            ->where('user_id', (int)$user->id)
            ->first();

        abort_if(!$visit, 404);
        abort_if((string)($visit->status ?? '') === 'done', 409, 'Sudah DONE.');

        // validasi halus: pastikan account_no match
        if (trim((string)$visit->account_no) !== trim((string)$data['account_no'])) {
            abort(422, 'account_no mismatch');
        }

        $back = trim((string)($data['back'] ?? ''));
        if ($back === '') $back = url()->previous();
        if ($back === '') $back = route('kpi.ro.os-daily');

        DB::beginTransaction();
        try {
            $update = [
                'lkh_note'   => $data['lkh_note'],
                'status'     => 'done',
                'updated_at' => now(),
            ];

            // ✅ hanya update kolom kalau memang ada (biar aman tanpa migrate dulu)
            if (Schema::hasColumn('ro_visits', 'visited_at')) {
                $update['visited_at'] = now();
            }
            if (Schema::hasColumn('ro_visits', 'lat')) {
                $update['lat'] = $data['lat'] ?? null;
            }
            if (Schema::hasColumn('ro_visits', 'lng')) {
                $update['lng'] = $data['lng'] ?? null;
            }

            DB::table('ro_visits')->where('id', (int)$visit->id)->update($update);

            // Upload foto tanpa symlink (public/uploads)
            if ($request->hasFile('photos') && Schema::hasTable('ro_visit_photos')) {
                $ym = now()->format('Y/m');
                $dir = public_path('uploads/ro-visits/' . $ym);
                if (!is_dir($dir)) @mkdir($dir, 0775, true);

                foreach ($request->file('photos') as $file) {
                    if (!$file) continue;

                    $name = now()->format('YmdHis') . '_' . uniqid() . '.' . $file->extension();
                    $file->move($dir, $name);

                    DB::table('ro_visit_photos')->insert([
                        'ro_visit_id' => (int)$visit->id,
                        'path'        => '/uploads/ro-visits/' . $ym . '/' . $name,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // ✅ setelah save, balik lagi ke form (biar user lihat sukses + preview foto)
        return redirect()
            ->route('ro_visits.create', ['account_no' => $visit->account_no, 'back' => $back])
            ->with('success', 'LKH berhasil disimpan (DONE).');
    }

    /**
     * Ambil loan_accounts posisi terakhir utk account_no.
     * Aman terhadap mismatch leading zero (010.. vs 10..).
     */
    private function getLoanAccountLatest(string $accountNo): ?object
    {
        $accountNo = trim($accountNo);
        if ($accountNo === '') return null;

        // 1) pos terakhir exact match
        $pos = DB::table('loan_accounts')
            ->where('account_no', $accountNo)
            ->max('position_date');

        // 2) fallback: compare tanpa leading zero
        if (!$pos) {
            $pos = DB::table('loan_accounts')
                ->whereRaw("TRIM(LEADING '0' FROM account_no) = TRIM(LEADING '0' FROM ?)", [$accountNo])
                ->max('position_date');
        }

        if (!$pos) return null;

        // ambil row posisi terakhir
        return DB::table('loan_accounts')
            ->select([
                'account_no',
                'customer_name',
                DB::raw('ROUND(outstanding) as outstanding'),
                'dpd',
                'kolek',
                'position_date',
            ])
            ->whereDate('position_date', $pos)
            ->where(function ($q) use ($accountNo) {
                $q->where('account_no', $accountNo)
                  ->orWhereRaw("TRIM(LEADING '0' FROM account_no) = TRIM(LEADING '0' FROM ?)", [$accountNo]);
            })
            ->first();
    }
}
