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

        $backUrl = (string) $request->query('back', url()->previous());

        // =========================
        // MODE A: RKH (punya rkh_detail_id)
        // =========================
        $rkhDetailId = (int) $request->query('rkh_detail_id', 0);
        if ($rkhDetailId > 0) {

            $detail = \App\Models\RkhDetail::with('header')->findOrFail($rkhDetailId);

            // pastikan ini RKH milik user tsb
            abort_if((int)($detail->header?->user_id ?? 0) !== (int)$user->id, 403, 'Bukan RKH kamu.');

            $accountNo = trim((string)($detail->account_no ?? ''));

            // Kalau belum ada account_no => arahkan ke form RKH visit log
            if ($accountNo === '') {
                return redirect()
                    ->route('rkh_visits.create', $detail->id)
                    ->with('status', 'Prospect belum punya account_no. Isi log kunjungan dulu. Setelah jadi nasabah, lakukan Link account.');
            }

            // ensure ro_visits planned hari ini ada
            $today = now()->toDateString();

            $visit = \App\Models\RoVisit::query()
                ->where('user_id', $user->id)
                ->where('account_no', $accountNo)
                ->where('visit_date', $today)
                ->first();

            if (!$visit) {
                $visit = \App\Models\RoVisit::create([
                    'rkh_detail_id' => $detail->id,         // ✅ penting
                    'user_id'       => $user->id,
                    'account_no'    => $accountNo,
                    'ao_code'       => $detail->header?->ao_code
                                    ?? $detail->ao_code
                                    ?? ($request->user()->ao_code ?? null), // optional sesuai strukturmu
                    'visit_date'    => $today,
                    'status'        => 'planned',
                    'source'        => 'rkh',
                ]);
            } else {
                // kalau sudah ada visit tapi rkh_detail_id kosong, kita isi biar nyambung
                if (empty($visit->rkh_detail_id)) {
                    $visit->rkh_detail_id = $detail->id;
                    $visit->source = $visit->source ?: 'rkh';
                    $visit->save();
                }
            }

            // ✅ ambil debitur info dari loan_accounts
            $deb = $this->getLoanAccountLatest((string) $visit->account_no);

            // ✅ ambil foto jika ada table ro_visit_photos
            $photos = collect();
            if (Schema::hasTable('ro_visit_photos')) {
                $photos = DB::table('ro_visit_photos')
                    ->where('ro_visit_id', (int) $visit->id)
                    ->orderBy('id')
                    ->get();
            }

            return view('ro_visits.form', [
                'visit'         => $visit,
                'deb'           => $deb,
                'photos'        => $photos,
                'back'          => $backUrl,
                'rkh_detail_id' => $detail->id,
                'from_rkh'      => true,
            ]);
        }

        // =========================
        // MODE B: PLAN TODAY (legacy)
        // =========================
        $accountNo = trim((string)$request->query('account_no', ''));
        abort_if($accountNo === '', 422, 'account_no required');

        $today = now()->toDateString();

        $visit = \App\Models\RoVisit::query()
            ->where('user_id', $user->id)
            ->where('account_no', $accountNo)
            ->where('visit_date', $today)
            ->first();

        abort_if(!$visit, 404, 'Visit belum di-plan.');

        $deb = $this->getLoanAccountLatest((string) $visit->account_no);

        $photos = collect();
        if (Schema::hasTable('ro_visit_photos')) {
            $photos = DB::table('ro_visit_photos')
                ->where('ro_visit_id', (int) $visit->id)
                ->orderBy('id')
                ->get();
        }

        return view('ro_visits.form', [
            'visit'    => $visit,
            'deb'      => $deb,
            'photos'   => $photos,
            'back'     => $backUrl,
            'from_rkh' => false,
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

        // tentukan nama kolom yang ada
        $osCol = null;
        foreach (['outstanding', 'os', 'baki_debet', 'saldo_pokok'] as $c) {
            if (Schema::hasColumn('loan_accounts', $c)) { $osCol = $c; break; }
        }

        $dpdCol = null;
        foreach (['dpd', 'hari_tunggakan', 'days_past_due'] as $c) {
            if (Schema::hasColumn('loan_accounts', $c)) { $dpdCol = $c; break; }
        }

        $kolekCol = null;
        foreach (['kolek', 'kolektibilitas'] as $c) {
            if (Schema::hasColumn('loan_accounts', $c)) { $kolekCol = $c; break; }
        }

        // minimal field wajib
        $select = ['account_no'];

        if (Schema::hasColumn('loan_accounts', 'customer_name')) {
            $select[] = 'customer_name';
        } elseif (Schema::hasColumn('loan_accounts', 'nama_nasabah')) {
            $select[] = DB::raw('nama_nasabah as customer_name');
        } else {
            $select[] = DB::raw("NULL as customer_name");
        }

        $select[] = $osCol
            ? DB::raw("ROUND($osCol) as outstanding")
            : DB::raw("0 as outstanding");

        $select[] = $dpdCol
            ? DB::raw("$dpdCol as dpd")
            : DB::raw("0 as dpd");

        $select[] = $kolekCol
            ? DB::raw("$kolekCol as kolek")
            : DB::raw("NULL as kolek");

        if (Schema::hasColumn('loan_accounts', 'position_date')) {
            $select[] = 'position_date';
        } else {
            $select[] = DB::raw("NULL as position_date");
        }

        // 1) posisi terakhir exact match
        $pos = Schema::hasColumn('loan_accounts', 'position_date')
            ? DB::table('loan_accounts')->where('account_no', $accountNo)->max('position_date')
            : null;

        // 2) fallback: compare tanpa leading zero
        if (!$pos && Schema::hasColumn('loan_accounts', 'position_date')) {
            $pos = DB::table('loan_accounts')
                ->whereRaw("TRIM(LEADING '0' FROM account_no) = TRIM(LEADING '0' FROM ?)", [$accountNo])
                ->max('position_date');
        }

        // kalau tidak ada position_date, ambil latest row by id saja
        if (!Schema::hasColumn('loan_accounts', 'position_date')) {
            return DB::table('loan_accounts')
                ->select($select)
                ->where(function ($q) use ($accountNo) {
                    $q->where('account_no', $accountNo)
                    ->orWhereRaw("TRIM(LEADING '0' FROM account_no) = TRIM(LEADING '0' FROM ?)", [$accountNo]);
                })
                ->orderByDesc('id')
                ->first();
        }

        if (!$pos) return null;

        return DB::table('loan_accounts')
            ->select($select)
            ->whereDate('position_date', $pos)
            ->where(function ($q) use ($accountNo) {
                $q->where('account_no', $accountNo)
                ->orWhereRaw("TRIM(LEADING '0' FROM account_no) = TRIM(LEADING '0' FROM ?)", [$accountNo]);
            })
            ->first();
    }

    public function planToday(Request $request)
    {
        $user = $request->user();
        abort_if(!$user, 401);

        $data = $request->validate([
            'account_no'       => ['required','string','max:30'],
            'nama_nasabah'     => ['nullable','string','max:255'],
            'kolektibilitas'   => ['nullable','in:L0,LT,DPK'],
            'jenis_kegiatan'   => ['nullable','string','max:255'],
            'tujuan_kegiatan'  => ['nullable','string','max:255'],
        ]);

        $today     = now()->toDateString();
        $accountNo = trim((string)$data['account_no']);
        abort_if($accountNo === '', 422, 'account_no empty');

        // ===== konfigurasi slot =====
        $dayStart  = '09:00:00';
        $durMin    = 120;  // 90 atau 120 (1.5 jam = 90)
        $bufferMin = 0;    // misal 15 kalau ingin jeda antar visit
        $maxPerDay = 5;

        return DB::transaction(function () use (
            $user, $today, $data, $accountNo,
            $dayStart, $durMin, $bufferMin, $maxPerDay
        ) {

            // ==========================
            // 1) HEADER: 1 user 1 tanggal
            // ==========================
            DB::table('rkh_headers')->updateOrInsert(
                ['user_id' => (int)$user->id, 'tanggal' => $today],
                [
                    'status'     => 'draft',
                    'total_jam'  => DB::raw('COALESCE(total_jam,0)'),
                    'updated_at' => now(),
                    'created_at' => now()
                ]
            );

            // lock header biar aman dari race condition (klik cepat / multi tab)
            $rkh = DB::table('rkh_headers')
                ->where('user_id', (int)$user->id)
                ->whereDate('tanggal', $today)
                ->lockForUpdate()
                ->first();

            $rkhId = (int) ($rkh->id ?? 0);
            abort_if($rkhId <= 0, 500, 'RKH header not found');

            // ==========================
            // 2) Jika account sudah diplan -> idempotent (jangan reset jam)
            // ==========================
            $existing = DB::table('rkh_details')
                ->where('rkh_id', $rkhId)
                ->where('account_no', $accountNo)
                ->first();

            if ($existing) {
                return response()->json([
                    'ok'              => true,
                    'message'         => 'Sudah diplan (idempotent).',
                    'plan_visit_date' => $today,
                    'rkh_id'          => $rkhId,
                    'account_no'      => $accountNo,
                    'jam_mulai'       => $existing->jam_mulai,
                    'jam_selesai'     => $existing->jam_selesai,
                ]);
            }

            // ==========================
            // 3) Max 5 visit per hari
            // ==========================
            $countToday = DB::table('rkh_details')
                ->where('rkh_id', $rkhId)
                ->count();

            abort_if($countToday >= $maxPerDay, 422, "Maksimal {$maxPerDay} visit per hari.");

            // ==========================
            // 4) AUTO FILL dari loan_accounts (posisi terakhir)
            // ==========================
            $nama  = $data['nama_nasabah'] ?? null;
            $kolek = $data['kolektibilitas'] ?? null;

            if ($nama === null || $kolek === null) {
                $pos = DB::table('loan_accounts')
                    ->where(function($q) use ($accountNo) {
                        $q->where('account_no', $accountNo)
                        ->orWhereRaw("TRIM(LEADING '0' FROM account_no) = TRIM(LEADING '0' FROM ?)", [$accountNo]);
                    })
                    ->max('position_date');

                if ($pos) {
                    $la = DB::table('loan_accounts')
                        ->select('customer_name','ft_pokok','ft_bunga','kolek')
                        ->whereDate('position_date', $pos)
                        ->where(function($q) use ($accountNo) {
                            $q->where('account_no', $accountNo)
                            ->orWhereRaw("TRIM(LEADING '0' FROM account_no) = TRIM(LEADING '0' FROM ?)", [$accountNo]);
                        })
                        ->first();

                    if ($la) {
                        if ($nama === null) $nama = $la->customer_name ?? null;

                        if ($kolek === null) {
                            $fp = (int)($la->ft_pokok ?? 0);
                            $fb = (int)($la->ft_bunga ?? 0);
                            $k  = (int)($la->kolek ?? 0);

                            if ($fp === 2 || $fb === 2 || $k === 2) $kolek = 'DPK';
                            elseif ($fp === 1 || $fb === 1)         $kolek = 'LT';
                            else                                     $kolek = 'L0';
                        }
                    }
                }
            }

            // ==========================
            // 5) AUTO SLOT JAM (append ke slot terakhir)
            // ==========================
            $lastEnd = DB::table('rkh_details')
                ->where('rkh_id', $rkhId)
                ->whereNotNull('jam_selesai')
                ->orderByDesc('jam_selesai')
                ->value('jam_selesai');

            $start = $lastEnd ?: $dayStart;

            // Carbon butuh tanggal + jam agar bisa addMinutes
            $startAt = \Carbon\Carbon::parse($today.' '.$start)->addMinutes($bufferMin);
            $endAt   = $startAt->copy()->addMinutes($durMin);

            $jamMulai   = $startAt->format('H:i:s');
            $jamSelesai = $endAt->format('H:i:s');

            // ==========================
            // 6) Anti tabrakan (overlap check)
            // ==========================
            $overlap = DB::table('rkh_details')
                ->where('rkh_id', $rkhId)
                ->where('jam_mulai', '<', $jamSelesai)
                ->where('jam_selesai', '>', $jamMulai)
                ->exists();

            abort_if($overlap, 422, 'Tabrakan jadwal visit. (Overlap slot waktu)');

            // ==========================
            // 7) INSERT detail (bukan updateOrInsert)
            // ==========================
            DB::table('rkh_details')->insert([
                'rkh_id'          => $rkhId,
                'account_no'      => $accountNo,
                'nama_nasabah'    => $nama,
                'kolektibilitas'  => $kolek,
                'jenis_kegiatan'  => $data['jenis_kegiatan'] ?? 'Visit',
                'tujuan_kegiatan' => $data['tujuan_kegiatan'] ?? 'Kunjungan nasabah',
                'jam_mulai'       => $jamMulai,
                'jam_selesai'     => $jamSelesai,
                'updated_at'      => now(),
                'created_at'      => now(),
            ]);

            return response()->json([
                'ok'              => true,
                'message'         => 'Planned & masuk RKH + slot otomatis.',
                'plan_visit_date' => $today,
                'rkh_id'          => $rkhId,
                'account_no'      => $accountNo,
                'nama_nasabah'    => $nama,
                'kolektibilitas'  => $kolek,
                'jam_mulai'       => $jamMulai,
                'jam_selesai'     => $jamSelesai,
            ]);
        });
    }


}
