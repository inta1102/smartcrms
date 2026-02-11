<?php

namespace App\Services\Rkh;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RkhSmartReminderService
{
    /**
     * Viewer = siapa yang melihat (RO/AO/TL/Kasi/Kabag)
     */
    public function forViewer(User $viewer, ?string $asOfYmd = null, int $limit = 100): array
    {
        $aoCodes = $this->resolveAoCodesForViewer($viewer);
        if (empty($aoCodes)) {
            Log::info('[RKH][SMART] no aoCodes resolved', ['user_id' => $viewer->id]);
            return [];
        }

        Log::info('[RKH][SMART] viewer-resolve', [
            'user_id' => $viewer->id,
            'role' => $this->roleValueSafe($viewer),
            'ao_codes_count' => count($aoCodes),
            'ao_codes_sample' => array_slice($aoCodes, 0, 10),
        ]);

        $asOf = $asOfYmd ? Carbon::parse($asOfYmd) : now();

        Log::info('[RKH][SMART] start', [
            'user_id' => $viewer->id,
            'user_name' => $viewer->name,
            'ao_codes_count' => count($aoCodes),
        ]);

        // ========== 1) Latest position_date per ao_code ==========
        $latestPosPerAo = DB::table('loan_accounts')
            ->select('ao_code', DB::raw('MAX(position_date) as max_pos'))
            ->whereIn('ao_code', $aoCodes)
            ->where('is_active', 1)
            ->groupBy('ao_code');

        // global currentPos untuk hitung snapshot bulan lalu (umumnya snapshot sama untuk semua)
        $currentPosGlobal = DB::query()
            ->fromSub($latestPosPerAo, 'lp')
            ->max('max_pos');

        if (!$currentPosGlobal) {
            Log::warning('[RKH][SMART] no loan_accounts for ao_codes -> return []', [
                'ao_codes_sample' => array_slice($aoCodes, 0, 10),
            ]);
            return [];
        }

        $currentPosGlobal = Carbon::parse($currentPosGlobal)->toDateString();
        $currentMonthStart = Carbon::parse($currentPosGlobal)->startOfMonth()->toDateString();

        // ========== 2) Snapshot bulan lalu (ambil max < awal bulan current) ==========
        $prevSnapMonth = DB::table('loan_account_snapshots_monthly')
            ->whereIn('ao_code', $aoCodes)
            ->where('snapshot_month', '<', $currentMonthStart)
            ->max('snapshot_month');

        // ========== 3) Ambil current data: join loan_accounts dengan latestPosPerAo ==========
        $currQ = DB::table('loan_accounts as c')
            ->joinSub($latestPosPerAo, 'lp', function ($j) {
                $j->on('lp.ao_code', '=', 'c.ao_code')
                    ->on('lp.max_pos', '=', 'c.position_date');
            })
            ->select([
                'c.account_no', 'c.cif', 'c.customer_name',
                'c.kolek', 'c.dpd', 'c.plafond',
                'c.ft_pokok', 'c.ft_bunga',
                'c.maturity_date', 'c.installment_day',
                'c.ao_code', 'c.position_date',
            ])
            ->where('c.is_active', 1);

        if ($prevSnapMonth) {
            $currQ->leftJoin('loan_account_snapshots_monthly as s', function ($j) use ($prevSnapMonth) {
                $j->on('s.account_no', '=', 'c.account_no')
                    ->where('s.snapshot_month', '=', $prevSnapMonth);
            });

            $currQ->addSelect([
                DB::raw('s.kolek as prev_kolek'),
                DB::raw('s.dpd as prev_dpd'),
                DB::raw('s.outstanding as prev_outstanding'),
                DB::raw('s.ft_pokok as prev_ft_pokok'),
                DB::raw('s.ft_bunga as prev_ft_bunga'),
                DB::raw('s.snapshot_month as prev_snapshot_month'),
            ]);
        } else {
            $currQ->addSelect([
                DB::raw('NULL as prev_kolek'),
                DB::raw('NULL as prev_dpd'),
                DB::raw('NULL as prev_outstanding'),
                DB::raw('NULL as prev_ft_pokok'),
                DB::raw('NULL as prev_ft_bunga'),
                DB::raw('NULL as prev_snapshot_month'),
            ]);
        }

        $rows = $currQ->limit(5000)->get();

        $bigPlafon = (int)config('rkh.plafon_big', 500_000_000);
        $out = [];

        foreach ($rows as $r) {
            $currLabel = $this->statusLabel(
                (int)($r->kolek ?? 0),
                (int)($r->ft_pokok ?? 0),
                (int)($r->ft_bunga ?? 0)
            );

            $prevLabel = $this->statusLabel(
                (int)($r->prev_kolek ?? 0),
                (int)($r->prev_ft_pokok ?? 0),
                (int)($r->prev_ft_bunga ?? 0)
            );

            $reasons = [];

            // ========= RULES =========
            // Catatan definisi:
            // - LT = FT 1 (ft_pokok==1 atau ft_bunga==1)
            // - DPK = FT 2 (ft_pokok==2 atau ft_bunga==2)
            // Maka: LT -> DPK = memburuk (FT naik 1 ke 2)

            // A) Akhir bulan kemarin (snapshot)
            if ($prevLabel === 'LT' && $currLabel === 'DPK') $reasons[] = 'Memburuk: akhir bulan kemarin LT → DPK';
            if ($prevLabel === 'L0' && $currLabel === 'LT')  $reasons[] = 'Memburuk: akhir bulan kemarin L0 → LT';
            if ($prevLabel === 'DPK' && $currLabel === 'LT') $reasons[] = 'Membaik: akhir bulan kemarin DPK → LT';
            if ($prevLabel === 'LT')                         $reasons[] = 'Akhir bulan kemarin LT';

            // B) Migrasi bulan ini (dibaca dari perubahan antara snapshot prev vs current)
            if ($prevLabel === 'LT' && $currLabel === 'DPK' && Carbon::parse($r->position_date)->isSameMonth($asOf)) {
                $reasons[] = 'Migrasi memburuk LT → DPK bulan ini';
            }
            if ($prevLabel === 'L0' && $currLabel === 'LT' && Carbon::parse($r->position_date)->isSameMonth($asOf)) {
                $reasons[] = 'Migrasi memburuk L0 → LT bulan ini';
            }
            if ($prevLabel === 'DPK' && $currLabel === 'LT' && Carbon::parse($r->position_date)->isSameMonth($asOf)) {
                $reasons[] = 'Migrasi membaik DPK → LT bulan ini';
            }

            // JT kredit bulan ini
            if (!empty($r->maturity_date) && Carbon::parse($r->maturity_date)->isSameMonth(Carbon::parse($r->position_date))) {
                $reasons[] = 'JT kredit bulan ini';
            }

            // JT angsuran minggu ini
            if (!empty($r->installment_day) && $this->isInstallmentDueThisWeek((int)$r->installment_day, (string)$r->position_date)) {
                $reasons[] = 'JT angsuran minggu ini';
            }

            // Plafon > threshold
            if ((float)($r->plafond ?? 0) >= $bigPlafon) $reasons[] = 'Plafon > 500 jt';

            // bonus DPD >= 7
            if ((int)($r->dpd ?? 0) >= 7) $reasons[] = 'DPD ≥ 7';

            $reasons = array_values(array_unique($reasons));
            if (empty($reasons)) continue;

            $suggestCatatan = 'SmartReminder: ' . implode('; ', $reasons);

            // ✅ pilih jenis berbasis alasan
            $jenisSug = $this->guessJenisFromReasons($reasons);

            // ✅ tujuan harus valid sesuai jenis -> ambil default dari master DB
            $tujuanSug = $this->defaultTujuanForJenis($jenisSug);

            $out[] = [
                'nasabah_id'   => null,
                'nama'         => (string)($r->customer_name ?? ''),
                'account_no'   => (string)($r->account_no ?? ''),
                'cif'          => (string)($r->cif ?? ''),
                'ao_code'      => (string)($r->ao_code ?? ''),
                'kolek_last'   => $prevLabel ?: '-',
                'kolek_now'    => $currLabel ?: '-',
                'dpd'          => (int)($r->dpd ?? 0),
                'plafon'       => (float)($r->plafond ?? 0),
                'position_date'=> (string)($r->position_date ?? ''),
                'prev_snapshot_month' => $r->prev_snapshot_month ? (string)$r->prev_snapshot_month : null,
                'reasons'      => $reasons,
                'suggest'      => [
                    'kolektibilitas' => $currLabel ?: '',
                    'jenis_kegiatan' => $jenisSug,
                    'tujuan_kegiatan'=> $tujuanSug, // ✅ dijamin ada di master_tujuan_kegiatan (kalau master benar)
                    'catatan'        => $suggestCatatan,
                ],
            ];

        }

        // ranking (prioritas alasan):
        // 1) JT kredit bulan ini
        // 2) Akhir bulan kemarin LT
        // 3) Migrasi memburuk L0 → LT bulan ini
        // 4) JT angsuran minggu ini
        // 5) DPD ≥ 7
        // 6) Plafon > 500 jt
        $scoreByReasons = function(array $reasons): int {
            $score = 0;

            $has = function(string $needle) use ($reasons): bool {
                foreach ($reasons as $r) {
                    if ($r === $needle) return true;
                    if (stripos($r, $needle) !== false) return true; // aman kalau label beda sedikit
                }
                return false;
            };

            if ($has('JT kredit bulan ini')) $score += 1_000_000;

            // tetap pakai exact label yang sudah kamu buat
            if ($has('Akhir bulan kemarin LT')) $score += 900_000;

            // Migrasi memburuk L0 → LT bulan ini
            if ($has('Migrasi memburuk L0 → LT bulan ini')) $score += 800_000;

            if ($has('JT angsuran minggu ini')) $score += 700_000;

            if ($has('DPD ≥ 7')) $score += 600_000;

            // plafon terakhir
            if ($has('Plafon > 500 jt')) $score += 100_000;

            return $score;
        };

        // ranking (prioritas berjenjang, bukan skor total):
        // 1) JT kredit bulan ini
        // 2) Akhir bulan kemarin LT
        // 3) Migrasi memburuk L0 → LT bulan ini
        // 4) JT angsuran minggu ini
        // 5) DPD ≥ 7
        // 6) Plafon > 500 jt
        usort($out, function ($a, $b) {

            $has = function(array $reasons, string $needle): bool {
                foreach ($reasons as $r) {
                    if ($r === $needle) return true;
                    if (stripos((string)$r, $needle) !== false) return true; // toleran variasi teks
                }
                return false;
            };

            $ra = (array)($a['reasons'] ?? []);
            $rb = (array)($b['reasons'] ?? []);

            // kunci prioritas: 1 kalau punya alasan tsb, 0 kalau tidak
            $pa = [
                $has($ra, 'JT kredit bulan ini') ? 1 : 0,
                $has($ra, 'Akhir bulan kemarin LT') ? 1 : 0,
                $has($ra, 'Migrasi memburuk L0 → LT bulan ini') ? 1 : 0,
                $has($ra, 'JT angsuran minggu ini') ? 1 : 0,
                $has($ra, 'DPD ≥ 7') ? 1 : 0,
                $has($ra, 'Plafon > 500 jt') ? 1 : 0,
            ];

            $pb = [
                $has($rb, 'JT kredit bulan ini') ? 1 : 0,
                $has($rb, 'Akhir bulan kemarin LT') ? 1 : 0,
                $has($rb, 'Migrasi memburuk L0 → LT bulan ini') ? 1 : 0,
                $has($rb, 'JT angsuran minggu ini') ? 1 : 0,
                $has($rb, 'DPD ≥ 7') ? 1 : 0,
                $has($rb, 'Plafon > 500 jt') ? 1 : 0,
            ];

            // bandingkan berjenjang sesuai urutan prioritas
            for ($i = 0; $i < count($pa); $i++) {
                if ($pa[$i] !== $pb[$i]) {
                    return $pb[$i] <=> $pa[$i]; // yang "punya" (1) menang
                }
            }

            // tie-breaker 1: dpd lebih tinggi dulu
            $da = (int)($a['dpd'] ?? 0);
            $db = (int)($b['dpd'] ?? 0);
            if ($db !== $da) return $db <=> $da;

            // tie-breaker 2: plafon lebih besar dulu
            $pla = (float)($a['plafon'] ?? 0);
            $plb = (float)($b['plafon'] ?? 0);
            if ($plb !== $pla) return $plb <=> $pla;

            // tie-breaker 3: nama asc
            return strcmp((string)($a['nama'] ?? ''), (string)($b['nama'] ?? ''));
        });

        return array_slice($out, 0, $limit);
    }

    /**
     * Ini bagian scope. Kamu sesuaikan sesuai struktur org di CRMS.
     */
    private function resolveAoCodesForViewer(User $user): array
    {
        $role = $this->roleValueSafe($user); // string aman

        // Role yang normally punya ao_code sendiri (lihat data sendiri)
        $selfAo = trim((string)($user->ao_code ?? ''));
        $leaderRoles = ['TL', 'TLRO', 'TLSO', 'TLFE', 'TLBE', 'TLUM', 'KSL', 'KSA', 'KBO', 'KSO', 'KOM', 'DIR', 'PE'];

        // kalau bukan leader role dan punya ao_code -> pakai ao_code sendiri
        if ($selfAo !== '' && !in_array($role, $leaderRoles, true)) {
            return [$selfAo];
        }

        // kalau leader role -> ambil ao_code bawahan dari org_assignments
        // skema kamu: user_id (bawahan), leader_id (atasan)
        $subIds = DB::table('org_assignments')
            ->where('leader_id', (int)$user->id)
            ->where('is_active', 1)
            ->where(function ($q) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', now()->toDateString());
            })
            ->pluck('user_id')
            ->map(fn($x) => (int)$x)
            ->unique()
            ->values()
            ->all();

        if (empty($subIds)) {
            return [];
        }

        // ambil ao_code dari users bawahan
        return DB::table('users')
            ->whereIn('id', $subIds)
            ->whereNotNull('ao_code')
            ->where('ao_code', '!=', '')
            ->pluck('ao_code')
            ->map(fn($x) => trim((string)$x))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * ✅ Definisi kolek untuk Smart Reminder (mengikuti koreksi kamu)
     * - DPK = FT 2
     * - LT  = FT 1
     * - fallback: kolek 1 => L0, kolek 2 => DPK
     */
    private function statusLabel(int $kolek, int $ftPokok, int $ftBunga): string
    {
        // ✅ cek yang lebih buruk dulu
        if ($ftPokok === 2 || $ftBunga === 2) return 'DPK';
        if ($ftPokok === 1 || $ftBunga === 1) return 'LT';

        if ($kolek === 1) return 'L0';
        if ($kolek === 2) return 'DPK';
        return (string)$kolek;
    }

    private function isInstallmentDueThisWeek(int $installmentDay, string $posYmd): bool
    {
        $pos = Carbon::parse($posYmd);
        $day = min(max($installmentDay, 1), $pos->daysInMonth);
        $due = $pos->copy()->day($day);

        $start = $pos->copy()->startOfWeek();
        $end = $pos->copy()->endOfWeek();

        return $due->between($start, $end);
    }

    private function roleValueSafe(User $user): string
    {
        // prefer helper user->roleValue() kalau ada
        if (method_exists($user, 'roleValue')) {
            $v = strtoupper(trim((string)$user->roleValue()));
            if ($v !== '') return $v;
        }

        // fallback: user->role() returning enum?
        if (method_exists($user, 'role')) {
            $enum = $user->role(); // UserRole|null
            if ($enum instanceof \BackedEnum) return strtoupper(trim((string)$enum->value));
            if (is_string($enum)) return strtoupper(trim($enum));
        }

        // fallback: kolom level bisa enum/string
        $raw = $user->level ?? null;
        if ($raw instanceof \BackedEnum) return strtoupper(trim((string)$raw->value));
        if (is_string($raw)) return strtoupper(trim($raw));

        // fallback: kolom role (kalau ada)
        $raw2 = $user->role ?? null;
        if ($raw2 instanceof \BackedEnum) return strtoupper(trim((string)$raw2->value));
        if (is_string($raw2)) return strtoupper(trim($raw2));

        return '';
    }

    private function guessJenisFromReasons(array $reasons): string
    {
        $txt = strtolower(implode(' | ', array_map('strval', $reasons)));

        // JT kredit / JT angsuran -> maintain
        if (str_contains($txt, 'jt kredit bulan ini') || str_contains($txt, 'jt angsuran minggu ini')) {
            return 'maintain';
        }

        // selain itu (dpd/lt/dpk/plafon/migrasi) -> penagihan
        return 'penagihan';
    }

    private function defaultTujuanForJenis(string $jenisCode): string
    {
        $jenisCode = (string) $jenisCode;

        // ambil default tujuan pertama yang aktif sesuai master DB
        $code = DB::table('master_tujuan_kegiatan')
            ->where('is_active', 1)
            ->where('jenis_code', $jenisCode)
            ->orderBy('sort')
            ->orderBy('id')
            ->value('code');

        return (string)($code ?? '');
    }

}
