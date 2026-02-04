<?php

namespace App\Imports;

use App\Models\LoanAccount;
use App\Models\LoanDisbursement; // ✅ NEW
use App\Models\NplCase;
use App\Models\ActionSchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class LoanAccountsImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    public int $total = 0;
    public int $inserted = 0;
    public int $updated = 0;  // untuk sekarang kita biarkan 0 (karena upsert sulit bedakan)
    public int $skipped = 0;

    public function summary(): array
    {
        return [
            'total'    => $this->total,
            'inserted' => $this->inserted,
            'updated'  => $this->updated,
            'skipped'  => $this->skipped,
        ];
    }

    protected string $positionDate;

    /** Rule version untuk audit (opsional) */
    protected string $ruleVersion = 'v2'; // ✅ update karena ada field baru

    public function __construct($positionDate)
    {
        $this->positionDate = Carbon::parse($positionDate)->format('Y-m-d');
    }

    public function collection(Collection $rows)
    {
        if ($rows->isEmpty()) return;
        $this->total += $rows->count();

        // debug header (boleh kamu biarkan sementara)
        if ($rows->isNotEmpty()) {
            $first = $rows->first();
            $r0 = is_array($first) ? $first : $first->toArray();
            Log::info('[IMPORT] first row keys', ['keys' => array_keys($r0)]);
        }

        $now = now();

        // 1) Siapkan payload upsert LoanAccount
        $loanUpsert     = []; // [account_no => data]
        $caseMap        = []; // [account_no => meta rule]
        $recoverAccNos  = []; // account_no yang tidak memenuhi rule (untuk recovered)

        // ✅ NEW: siapkan payload disbursement (bulk upsert kita lakukan via updateOrCreate loop - karena unique 3 kolom)
        $disbRows = []; // list rows: ['account_no','ao_code','disb_date','period','amount','cif','customer_name']

        // ✅ debug sample RS row (safe)
        static $dbgRs = 0;
        $dbgSampleRow = null;

        foreach ($rows as $row) {
            $r = is_array($row) ? $row : $row->toArray();

            // ===== mapping kolom dari file =====
            $accountNo      = $this->normalizeAccountNo($r['nofas'] ?? null);
            $cif            = $r['cno'] ?? null;
            $customerName   = $r['cnm'] ?? null;

            $kolek          = (int)($r['kolekskr'] ?? 0);
            $dpd            = (int)($r['hari_tunggak'] ?? 0);

            $ftPokokRaw =
                $r['ft_pokok']
                ?? $r['ftpokok']
                ?? $r['frek_tunggak_pokok']
                ?? null;

            $ftBungaRaw =
                $r['ft_bunga']
                ?? $r['ftbunga']
                ?? $r['frek_tunggak_bunga']
                ?? null;

            $ftPokok = $this->parseNonNegativeIntOrZero($ftPokokRaw);
            $ftBunga = $this->parseNonNegativeIntOrZero($ftBungaRaw);

            $plafond        = (float)($r['fasilitas'] ?? 0);
            $outstanding    = (float)($r['sldakhir'] ?? 0);

            $collectorCode  = $this->normalizeCode($r['kode_ao'] ?? null);
            $collectorName  = $r['nama_ao'] ?? null;

            $ownerAoCode    = $this->normalizeCode($r['kdcollector'] ?? null);
            $ownerAoName    = $r['nmcollector'] ?? null;

            $alamat         = $r['alamat'] ?? null;

            // ✅ NEW: disbursement date (kolom excel: tgl_valuta)
            $tglValutaRaw = $r['tgl_valuta'] ?? $r['tglvaluta'] ?? $r['valuta'] ?? null;
            $tglValuta = $this->parseDateOrNull($tglValutaRaw);

            // =========================
            // ✅ FIELD BARU - NILAI AGUNAN DIPERHITUNGKAN (CP)
            // =========================
            $nilaiAgunanHitungRaw =
                $r['nilai_jaminan']
                ?? $r['nilai_jaminan_diperhitungkan']
                ?? $r['jaminan_diperhitungkan']
                ?? $r['cp']
                ?? null;

            $nilaiAgunanDiperhitungkan = null;
            if ($nilaiAgunanHitungRaw !== null && $nilaiAgunanHitungRaw !== '') {
                $digits = preg_replace('/[^\d]/', '', (string)$nilaiAgunanHitungRaw);
                $nilaiAgunanDiperhitungkan = ($digits === '') ? null : (float)$digits;
            }

            // =========================
            // ✅ FIELD BARU (EWS) - RESTRUK
            // =========================
            $flagRsRaw = $r['flag_rs'] ?? $r['flag_restru'] ?? $r['flag_restruktur'] ?? $r['ea'] ?? null;
            $isRestructured = $this->parseBoolFlag($flagRsRaw);

            $lastRestrucRaw =
                $r['tglakhir_restruktur']
                ?? $r['tglakhir_restrukturisasi']
                ?? $r['tgl_akhir_restruktur']
                ?? $r['last_restructure_date']
                ?? $r['dz']
                ?? null;

            $lastRestructureDate = $this->parseDateOrNull($lastRestrucRaw);

            $instDayRaw = $r['tgl_angsuran'] ?? $r['installment_day'] ?? $r['eu'] ?? null;
            $installmentDay = $this->parseIntDayOrNull($instDayRaw);

            $lastPayRaw = $r['tgl_akhir_byr'] ?? $r['tgl_akhir_bayar'] ?? $r['last_payment_date'] ?? $r['fq'] ?? null;
            $lastPaymentDate = $this->parseDateOrNull($lastPayRaw);

            $freqRaw =
                $r['frek_restruktur']
                ?? $r['restructure_freq']
                ?? null;

            $restructureFreq = $this->parseFreqOrDefault($freqRaw, $isRestructured);

            // =========================
            // ✅ FIELD BARU (EWS) - KOLEK 5 / USIA MACET
            // =========================
            $jenisAgunanRaw =
                $r['jenis_agunan']
                ?? $r['jenisagunan']
                ?? $r['al']
                ?? null;
            $jenisAgunan = is_null($jenisAgunanRaw) ? null : (int)$jenisAgunanRaw;
            if ($jenisAgunan === 0 && ($jenisAgunanRaw === '' || $jenisAgunanRaw === null)) {
                $jenisAgunan = null;
            }

            $tglKolekRaw =
                $r['tgl_kolek']
                ?? $r['tanggal_kolek']
                ?? $r['cy']
                ?? null;
            $tglKolek = $this->parseDateOrNull($tglKolekRaw);

            $keteranganSandi =
                $r['keterangan_sandi']
                ?? $r['ket_sandi']
                ?? $r['dd']
                ?? null;
            $keteranganSandi = $keteranganSandi ? trim((string)$keteranganSandi) : null;

            $cadanganPpapRaw =
                $r['cadangan_ppap']
                ?? $r['ppap']
                ?? $r['ag']
                ?? null;

            $cadanganPpap = null;
            if ($cadanganPpapRaw !== null && $cadanganPpapRaw !== '') {
                $cadanganPpap = (int) preg_replace('/[^\d]/', '', (string)$cadanganPpapRaw);
            }

            // posisi tanggal dari form (boleh override jika ada)
            $positionDate = $this->positionDate;
            if (!empty($r['tgl_posisi'])) {
                try {
                    $positionDate = Carbon::parse($r['tgl_posisi'])->format('Y-m-d');
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            // ===== filter baris invalid =====
            if (!$accountNo) continue;
            $accountNo = trim((string)$accountNo);

            if (!$customerName || trim((string)$customerName) === '') continue;
            $customerName = trim((string)$customerName);

            // ===== payload upsert loan_accounts =====
            $loanUpsert[$accountNo] = [
                'account_no'      => $accountNo,
                'position_date'   => $positionDate,
                'cif'             => $cif,
                'customer_name'   => $customerName,
                'kolek'           => $kolek,
                'dpd'             => $dpd,
                'ft_pokok'        => $ftPokok,
                'ft_bunga'        => $ftBunga,
                'plafond'         => $plafond,
                'outstanding'     => $outstanding,
                'ao_code'         => $ownerAoCode,
                'ao_name'         => $ownerAoName,
                'collector_code'  => $collectorCode,
                'collector_name'  => $collectorName,
                'alamat'          => $alamat,

                'nilai_agunan_yg_diperhitungkan' => $nilaiAgunanDiperhitungkan,

                'is_restructured'       => $isRestructured ? 1 : 0,
                'restructure_freq'      => $restructureFreq,
                'last_restructure_date' => $lastRestructureDate,
                'installment_day'       => $installmentDay,
                'last_payment_date'     => $lastPaymentDate,

                'jenis_agunan'      => $jenisAgunan,
                'tgl_kolek'         => $tglKolek,
                'keterangan_sandi'  => $keteranganSandi,
                'cadangan_ppap'     => $cadanganPpap,

                'is_active'       => true,
                'updated_at'      => $now,
                'created_at'      => $now,
            ];

            // =========================
            // ✅ NEW: RECORD DISBURSEMENT (tgl_valuta + fasilitas)
            // =========================
            // Note: kita catat jika:
            // - tgl_valuta ada
            // - fasilitas > 0
            // Dedup ditangani oleh unique key & updateOrCreate saat simpan
            if ($tglValuta && $plafond > 0) {
                $aoForDisb = $ownerAoCode ?: $collectorCode; // prioritas owner
                $disbRows[] = [
                    'account_no'    => $accountNo,
                    'ao_code'       => $aoForDisb ?: null,
                    'disb_date'     => $tglValuta,
                    'period'        => Carbon::parse($tglValuta)->startOfMonth()->toDateString(),
                    'amount'        => (int) round($plafond),
                    'cif'           => $cif,
                    'customer_name' => $customerName,
                ];
            }

            /**
             * ✅ RULE MASUK CASE:
             * - DPD > 15 (termasuk kolek 1)
             * - atau kolek >= 3
             * - atau kolek = 2 & dpd >= 30
             * - atau kredit restruktur (is_restruk = Y)
             */
            $isRestruk = in_array(
                strtoupper(trim($r['is_restruk'] ?? 'N')),
                ['Y', 'YES', '1'],
                true
            );

            $shouldOpenCase =
                $isRestruk
                || ($dpd > 15)
                || ($kolek >= 3)
                || ($kolek == 2 && $dpd >= 30);

            if ($shouldOpenCase) {
                $caseMap[$accountNo] = [
                    'priority'       => $this->getPriority($kolek, $dpd, $outstanding, $isRestruk),
                    'position_date'  => $positionDate,
                    'dpd'            => $dpd,
                    'kolek'          => $kolek,
                    'os'             => $outstanding,
                    'is_restruk'     => $isRestruk,
                ];
            } else {
                $recoverAccNos[] = $accountNo;
            }

            // ✅ Debug sample RS row (sekali aja)
            if ($dbgRs < 1) {
                $flag = strtoupper(trim((string)($r['flag_restruktur'] ?? $r['flag_rs'] ?? '')));
                if ($flag === 'Y') {
                    $dbgSampleRow = $r;
                    $dbgRs++;
                }
            }
        }

        if (empty($loanUpsert)) {
            Log::warning('[IMPORT] No rows parsed. Possible header mismatch.');
            throw new \RuntimeException('Tidak ada data terbaca. Kemungkinan header kolom tidak sesuai template.');
        }

        $this->inserted += count($loanUpsert);
        $this->skipped  += max(0, $rows->count() - count($loanUpsert));

        // =========================
        // A) UPSERT LOAN ACCOUNTS
        // =========================
        LoanAccount::upsert(
            array_values($loanUpsert),
            ['account_no'],
            [
                'position_date','cif','customer_name','kolek','dpd','plafond','outstanding',
                'ao_code','ao_name','collector_code','collector_name','alamat',
                'nilai_agunan_yg_diperhitungkan','ft_pokok','ft_bunga',
                'is_restructured','restructure_freq','last_restructure_date','installment_day','last_payment_date',
                'jenis_agunan','tgl_kolek','keterangan_sandi','cadangan_ppap',
                'is_active','updated_at',
            ]
        );

        // =========================
        // A2) UPSERT DISBURSEMENTS (from the same file)
        // =========================
        if (!empty($disbRows)) {
            // Dedup ringan dalam chunk (hindari updateOrCreate berulang utk key sama)
            $seen = [];
            foreach ($disbRows as $d) {
                $k = ($d['account_no'] ?? '').'|'.($d['disb_date'] ?? '').'|'.($d['amount'] ?? 0);
                if ($k === '||0') continue;
                if (isset($seen[$k])) continue;
                $seen[$k] = true;

                LoanDisbursement::query()->updateOrCreate(
                    [
                        'account_no' => $d['account_no'],
                        'disb_date'  => $d['disb_date'],
                        'amount'     => $d['amount'],
                    ],
                    [
                        'ao_code'       => $d['ao_code'] ?: null,
                        'user_id'       => null, // nanti bisa dimap dari ao_code
                        'period'        => $d['period'],
                        'cif'           => $d['cif'] ?: null,
                        'customer_name' => $d['customer_name'] ?: null,
                        'source_file'   => 'loan_accounts_import',
                        'import_batch_id' => null,
                        'updated_at'    => $now,
                        'created_at'    => $now,
                    ]
                );
            }
        }

        // Map account_no -> loan_account_id
        $accNosAll = array_keys($loanUpsert);
        $loanIdMap = LoanAccount::query()
            ->whereIn('account_no', $accNosAll)
            ->pluck('id', 'account_no');
        // =========================
        // A3) MAP loan_disbursements.user_id by ao_code
        // =========================
        $aoCodes = collect($disbRows)
            ->pluck('ao_code')
            ->filter(fn($v) => $v !== null && trim((string)$v) !== '')
            ->map(fn($v) => trim((string)$v))
            ->unique()
            ->values();

        if ($aoCodes->isNotEmpty()) {
            $userIdByEmp = User::query()
                ->whereIn('employee_code', $aoCodes->all())
                ->pluck('id', 'employee_code');

            // update hanya yang user_id masih null
            foreach ($userIdByEmp as $empCode => $uid) {
                LoanDisbursement::query()
                    ->whereNull('user_id')
                    ->where('ao_code', (string)$empCode)
                    ->update([
                        'user_id'    => (int)$uid,
                        'updated_at' => $now,
                    ]);
            }

            // fallback kalau ternyata ao_code kamu pakai users.ao_code
            $userIdByAo = User::query()
                ->whereIn('ao_code', $aoCodes->all())
                ->pluck('id', 'ao_code');

            foreach ($userIdByAo as $aoCode => $uid) {
                LoanDisbursement::query()
                    ->whereNull('user_id')
                    ->where('ao_code', (string)$aoCode)
                    ->update([
                        'user_id'    => (int)$uid,
                        'updated_at' => $now,
                    ]);
            }
        }

        // =========================
        // B0) MAP loan_account_id -> pic_user_id
        // =========================
        $loanPicMap = collect(); // [loan_account_id => pic_user_id]

        if (!empty($caseMap)) {
            $caseAccNos = array_keys($caseMap);

            $loanRows = LoanAccount::query()
                ->whereIn('account_no', $caseAccNos)
                ->get(['id', 'ao_code', 'collector_code']);

            $codes = $loanRows->map(function ($la) {
                    $code = $la->ao_code ?: $la->collector_code;
                    return $code ? trim((string)$code) : null;
                })
                ->filter()
                ->unique()
                ->values();

            $userIdByEmp = User::query()
                ->whereIn('employee_code', $codes->all())
                ->pluck('id', 'employee_code');

            $loanPicMap = $loanRows->mapWithKeys(function ($la) use ($userIdByEmp) {
                $code = $la->ao_code ?: $la->collector_code;
                $code = $code ? trim((string)$code) : null;

                $uid = $code ? ($userIdByEmp[$code] ?? null) : null;
                return [$la->id => $uid];
            });
        }

        // =========================
        // B) HANDLE CASES (create/update/reopen) - IMPORT ONLY
        // =========================
        if (!empty($caseMap)) {
            $caseAccNos  = array_keys($caseMap);
            $caseLoanIds = $loanIdMap->only($caseAccNos)->values()->all();

            if (!empty($caseLoanIds)) {
                $existingCases = NplCase::query()
                    ->whereIn('loan_account_id', $caseLoanIds)
                    ->get(['id','loan_account_id','status']);

                $caseByLoan = $existingCases->keyBy('loan_account_id');

                $newCases       = [];
                $updatePriority = []; // case_id => priority
                $reopenCases    = []; // case_id => ['priority'=>..,'reopened_at'=>..]
                $updatePic      = []; // case_id => pic_user_id

                foreach ($caseAccNos as $accNo) {
                    $loanId = (int)($loanIdMap[$accNo] ?? 0);
                    if ($loanId <= 0) continue;

                    $priority = (string)($caseMap[$accNo]['priority'] ?? 'normal');
                    $posDate  = (string)($caseMap[$accNo]['position_date'] ?? $this->positionDate);

                    $case = $caseByLoan->get($loanId);

                    if (!$case) {
                        $newCases[] = [
                            'loan_account_id' => $loanId,
                            'pic_user_id'     => $loanPicMap[$loanId] ?? null,
                            'status'          => 'open',
                            'priority'        => $priority,
                            'opened_at'       => $posDate,
                            'summary'         => 'Auto-detected from import file (DPD/Kolek rule).',
                            'created_at'      => $now,
                            'updated_at'      => $now,
                        ];
                    } else {
                        $pic = $loanPicMap[$loanId] ?? null;
                        if ($pic) {
                            $updatePic[(int)$case->id] = (int)$pic;
                        }

                        if (($case->status ?? '') !== 'open') {
                            $reopenCases[(int)$case->id] = [
                                'priority'    => $priority,
                                'reopened_at' => $posDate,
                            ];
                        } else {
                            $updatePriority[(int)$case->id] = $priority;
                        }
                    }
                }

                if (!empty($updatePic)) {
                    $this->bulkUpdateIntNullable('npl_cases', 'pic_user_id', $updatePic, $now);
                }

                if (!empty($newCases)) {
                    NplCase::insert($newCases);
                }

                if (!empty($reopenCases)) {
                    $this->bulkUpdateCasesOpenAndPriority($reopenCases, $now);
                }

                if (!empty($updatePriority)) {
                    $this->bulkUpdatePriority('npl_cases', 'priority', $updatePriority, $now);
                }
            }
        }

        // =========================
        // C) HANDLE RECOVERED CASES + CANCEL PENDING SCHEDULE
        // =========================
        if (!empty($recoverAccNos)) {
            $recoverAccNos  = array_values(array_unique($recoverAccNos));
            $recoverLoanIds = $loanIdMap->only($recoverAccNos)->values()->all();

            if (!empty($recoverLoanIds)) {
                $openCaseIds = NplCase::query()
                    ->whereIn('loan_account_id', $recoverLoanIds)
                    ->where('status', 'open')
                    ->pluck('id')
                    ->all();

                if (!empty($openCaseIds)) {
                    NplCase::whereIn('id', $openCaseIds)->update([
                        'status'        => 'recovered',
                        'closed_at'     => $this->positionDate,
                        'closed_by'     => 'SYSTEM',
                        'closed_reason' => 'DPD/Kolek membaik berdasarkan posisi '.$this->positionDate,
                        'updated_at'    => $now,
                    ]);

                    ActionSchedule::whereIn('npl_case_id', $openCaseIds)
                        ->where('status', 'pending')
                        ->update([
                            'status'       => 'cancelled',
                            'completed_at' => $now,
                            'updated_at'   => $now,
                        ]);
                }
            }
        }

        // =========================
        // DEBUG RS SAMPLE (safe)
        // =========================
        if (!empty($dbgSampleRow)) {
            try {
                $raw = $dbgSampleRow['tglakhir_restruktur'] ?? $dbgSampleRow['dz'] ?? null;

                \Log::info('[IMPORT DBG] RS row sample', [
                    'account_no' => $dbgSampleRow['noac'] ?? $dbgSampleRow['nofas'] ?? null,
                    'flag_restruktur' => $dbgSampleRow['flag_restruktur'] ?? $dbgSampleRow['flag_rs'] ?? null,
                    'tglakhir_restruktur_raw' => $raw,
                    'raw_type' => is_object($raw) ? get_class($raw) : gettype($raw),
                    'raw_dump' => is_object($raw)
                        ? (method_exists($raw, '__toString') ? (string)$raw : 'OBJECT')
                        : $raw,
                ]);
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    // =========================
    // Helpers bulk update
    // =========================

    private function bulkUpdateIntNullable(string $table, string $column, array $idToValue, $now): void
    {
        $ids = array_keys($idToValue);
        if (empty($ids)) return;

        $caseSql = "CASE id ";
        foreach ($idToValue as $id => $val) {
            $id = (int)$id;
            if ($val === null) {
                $caseSql .= "WHEN {$id} THEN NULL ";
            } else {
                $v = (int)$val;
                $caseSql .= "WHEN {$id} THEN {$v} ";
            }
        }
        $caseSql .= "END";

        $idList = implode(',', array_map('intval', $ids));

        $sql = "
            UPDATE {$table}
            SET {$column} = {$caseSql},
                updated_at = ?
            WHERE id IN ({$idList})
        ";

        DB::update($sql, [$now]);
    }

    private function bulkUpdateCasesOpenAndPriority(array $caseMap, $now): void
    {
        $ids = array_keys($caseMap);
        if (empty($ids)) return;

        $priorityCaseSql = 'CASE id ';
        $reopenedCaseSql = 'CASE id ';

        foreach ($caseMap as $id => $v) {
            $id = (int)$id;
            $p = addslashes((string)($v['priority'] ?? 'normal'));
            $d = addslashes((string)($v['reopened_at'] ?? $this->positionDate));
            $priorityCaseSql .= "WHEN {$id} THEN '{$p}' ";
            $reopenedCaseSql .= "WHEN {$id} THEN '{$d}' ";
        }
        $priorityCaseSql .= 'END';
        $reopenedCaseSql .= 'END';

        $idList = implode(',', array_map('intval', $ids));

        $sql = "
            UPDATE npl_cases
            SET
                status = 'open',
                priority = {$priorityCaseSql},
                reopened_at = {$reopenedCaseSql},
                updated_at = ?
            WHERE id IN ({$idList})
        ";

        DB::update($sql, [$now]);
    }

    private function bulkUpdatePriority(string $table, string $column, array $idToValue, $now): void
    {
        $ids = array_keys($idToValue);
        if (empty($ids)) return;

        $caseSql = "CASE id ";
        foreach ($idToValue as $id => $val) {
            $id = (int)$id;
            $v = addslashes((string)$val);
            $caseSql .= "WHEN {$id} THEN '{$v}' ";
        }
        $caseSql .= "END";

        $idList = implode(',', array_map('intval', $ids));

        $sql = "
            UPDATE {$table}
            SET {$column} = {$caseSql},
                updated_at = ?
            WHERE id IN ({$idList})
        ";

        DB::update($sql, [$now]);
    }

    // =========================
    // Parsing helpers (NEW)
    // =========================

    private function parseBoolFlag($v): bool
    {
        $s = strtoupper(trim((string)$v));
        return in_array($s, ['Y', 'YES', '1', 'TRUE'], true);
    }

    private function parseIntDayOrNull($v): ?int
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '') return null;
        if (!is_numeric($s)) return null;

        $i = (int)$s;
        return ($i >= 1 && $i <= 31) ? $i : null;
    }

    private function parseDateOrNull($raw): ?string
    {
        if ($raw === null || $raw === '') return null;

        // 1) Excel serial number (contoh: 45611)
        if (is_int($raw) || is_float($raw) || (is_numeric($raw) && (string)(int)$raw === (string)$raw)) {
            try {
                $dt = ExcelDate::excelToDateTimeObject((float)$raw);
                return $dt->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        // 2) String date biasa
        try {
            return \Carbon\Carbon::parse((string)$raw)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function parseFreqOrDefault($v, bool $isRestruc): int
    {
        if ($v === null || trim((string)$v) === '') {
            return $isRestruc ? 1 : 0;
        }

        $s = trim((string)$v);
        if (!is_numeric($s)) return $isRestruc ? 1 : 0;

        $i = (int)$s;
        if ($i < 0) $i = 0;
        if ($i > 255) $i = 255;
        return $i;
    }

    // =========================
    // Misc
    // =========================

    public function chunkSize(): int
    {
        return 1000;
    }

    protected function getPriority(int $kolek, int $dpd, float $os, bool $isRestruk): string
    {
        if ($isRestruk && $os >= 2500000000) return 'critical';
        if ($kolek >= 4 || $dpd >= 180 || $os >= 100000000) return 'critical';
        if ($kolek == 3 || $dpd >= 90) return 'high';
        return 'normal';
    }

    private function normalizeCode($value, int $pad = 6): ?string
    {
        if ($value === null) return null;

        $v = trim((string)$value);
        if ($v === '') return null;

        if (ctype_digit($v)) return str_pad($v, $pad, '0', STR_PAD_LEFT);

        return $v;
    }

    private function normalizeAccountNo($value, int $length = 13): ?string
    {
        if ($value === null) return null;

        $digits = preg_replace('/\D+/', '', (string)$value);
        if ($digits === '') return null;

        return str_pad($digits, $length, '0', STR_PAD_LEFT);
    }

    private function parseNonNegativeIntOrZero($v): int
    {
        if ($v === null || $v === '') return 0;

        $s = trim((string)$v);
        // kalau ada koma/titik dll
        $digits = preg_replace('/[^\d\-]/', '', $s);
        if ($digits === '' || $digits === '-') return 0;

        $i = (int)$digits;
        return $i < 0 ? 0 : $i;
    }

}
