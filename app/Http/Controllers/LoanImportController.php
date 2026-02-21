<?php

namespace App\Http\Controllers;

use App\Imports\LoanAccountsImport;
use App\Jobs\RebuildSpSchedulesForCaseJob;
use App\Jobs\SyncLegacySpForCaseJob;
use App\Models\ImportLog;
use App\Models\LegacySyncLog;
use App\Models\NplCase;
use App\Models\ScheduleUpdateLog;
use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Validators\ValidationException as ExcelValidationException;
use App\Jobs\RunMonthlyLoanSnapshot;
use App\Models\LegacySyncRun;
use Illuminate\Support\Facades\DB;
use App\Imports\LoanInstallmentsImport; 
use Illuminate\Support\Facades\Artisan;
use App\Imports\LoanAccountClosuresImport;


class LoanImportController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        // kalau mau: $this->middleware('can:importLoans');
    }

    public function import(Request $request)
    {
        // untuk local dev
        @ini_set('max_execution_time', '600');
        @set_time_limit(600);
        @ini_set('memory_limit', '1024M');

        $validated = $request->validate([
            'file'            => 'required|file|max:20480|mimes:xls,xlsx',
            'position_date'   => ['required', 'date'],
            'reimport'        => ['nullable', 'boolean'],
            'reimport_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $posDate  = Carbon::parse($validated['position_date'])->toDateString();
        $file     = $request->file('file');
        $fileName = $file?->getClientOriginalName();

        $reimport = (bool)($validated['reimport'] ?? false);
        $reason   = trim((string)($validated['reimport_reason'] ?? ''));

        // Cek pernah sukses import posisi yang sama
        $exists = ImportLog::query()
            ->where('module', 'loans')
            ->where('status', 'success')
            ->whereDate('position_date', $posDate)
            ->exists();

        if ($exists && !$reimport) {
            return back()
                ->withErrors(['position_date' => "Posisi data {$posDate} sudah pernah diimport (SUCCESS). Jika ada koreksi, gunakan Re-import."])
                ->withInput();
        }

        if ($exists && $reimport && $reason === '') {
            return back()
                ->withErrors(['reimport_reason' => 'Alasan re-import wajib diisi.'])
                ->withInput();
        }

        $importer = new LoanAccountsImport($posDate);

        try {
            Excel::import($importer, $file);

            // ✅ snapshot harian OS dibuat SEKALI setelah import selesai
            \Illuminate\Support\Facades\Artisan::call('kpi:os-daily-snapshot', ['--date' => $posDate]);

            $s = $importer->summary();

            ImportLog::create([
                'module'        => 'loans',
                'position_date' => $posDate,
                'run_type'      => $reimport ? 'reimport' : 'import',
                'file_name'     => $fileName,
                'rows_total'    => (int)($s['total'] ?? 0),
                'rows_inserted' => (int)($s['inserted'] ?? 0),
                'rows_updated'  => (int)($s['updated'] ?? 0),
                'rows_skipped'  => (int)($s['skipped'] ?? 0),
                'status'        => 'success',
                'message'       => ($reimport ? 'RE-IMPORT sukses' : 'IMPORT sukses')
                                    . " | parsed={$s['total']} upsert={$s['inserted']} skipped={$s['skipped']}",
                'reason'        => $reimport ? $reason : null,
                'imported_by'   => auth()->id(),
            ]);

            // ✅ Auto snapshot bulanan saat closing (agar growth tidak bolong)
            RunMonthlyLoanSnapshot::dispatch($posDate)->onQueue('default');

            return redirect()
                ->route('loans.import.form')
                ->with('status', ($reimport ? 'Re-import' : 'Import') . " selesai. Posisi: {$posDate}. Total: {$s['total']} | Upsert: {$s['inserted']} | Skipped: {$s['skipped']}");

        } catch (ExcelValidationException $e) {

            Log::error('[IMPORT] ExcelValidationException', ['failures' => $e->failures()]);

            ImportLog::create([
                'module'        => 'loans',
                'position_date' => $posDate,
                'run_type'      => $reimport ? 'reimport' : 'import',
                'file_name'     => $fileName,
                'status'        => 'failed',
                'message'       => 'Header/kolom tidak sesuai template (ValidationException).',
                'reason'        => $reimport ? $reason : null,
                'imported_by'   => auth()->id(),
            ]);

            return back()
                ->with('error', 'Gagal import: header/kolom tidak sesuai template. (cek log)')
                ->withInput();

        } catch (\Throwable $e) {

            Log::error('[IMPORT] Throwable', [
                'msg'  => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            ImportLog::create([
                'module'        => 'loans',
                'position_date' => $posDate,
                'run_type'      => $reimport ? 'reimport' : 'import',
                'file_name'     => $fileName,
                'status'        => 'failed',
                'message'       => 'Import gagal: ' . $e->getMessage(),
                'reason'        => $reimport ? $reason : null,
                'imported_by'   => auth()->id(),
            ]);

            return back()
                ->with('error', 'Gagal mengimport file. Detail error ada di log.')
                ->withInput();
        }
    }

    public function showForm()
    {
        $lastImport = ImportLog::query()
            ->with('importer:id,name')
            ->where('module', 'loans')
            ->latest('id')
            ->first();

        $posDate = $lastImport?->position_date;

        $lastLegacy = LegacySyncLog::query()
            ->with('runner:id,name')
            ->when($posDate, fn ($q) => $q->whereDate('position_date', $posDate))
            ->latest('id')
            ->first();

        $lastSchedule = ScheduleUpdateLog::query()
            ->with('runner:id,name')
            ->when($posDate, fn ($q) => $q->whereDate('position_date', $posDate))
            ->latest('id')
            ->first();

        // ✅ NEW: import installment terakhir (ikut posisi terakhir biar nyambung)
        $lastInstallment = ImportLog::query()
            ->with('importer:id,name')
            ->where('module', 'installments')
            ->when($posDate, fn ($q) => $q->whereDate('position_date', $posDate))
            ->latest('id')
            ->first();

        return view('loans.import', compact(
            'lastImport',
            'lastLegacy',
            'lastSchedule',
            'lastInstallment'
        ));
    }

    public function legacySync(Request $request)
    {
        $request->validate([
            'position_date' => ['required', 'date'],
        ]);

        $posDate = Carbon::parse($request->input('position_date'))->toDateString();

        // Cegah double-run posisi sama
        $running = LegacySyncLog::query()
            ->whereDate('position_date', $posDate)
            ->where('status', 'running')
            ->latest('id')
            ->first();

        if ($running) {
            return back()->with('error', "Legacy Sync posisi {$posDate} masih berjalan. Tunggu selesai.");
        }

        $importOk = ImportLog::query()
            ->where('module', 'loans')
            ->where('status', 'success')
            ->whereDate('position_date', $posDate)
            ->exists();

        if (!$importOk) {
            return back()->with('error', "Step 1 belum sukses untuk posisi {$posDate}. Lakukan Import dulu.");
        }

        $caseIds = NplCase::query()
            ->where('status', 'open')
            ->whereHas('loanAccount', fn ($q) => $q->whereDate('position_date', $posDate))
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();

        if (empty($caseIds)) {
            return back()->with('error', "Tidak ada case OPEN pada posisi {$posDate}.");
        }

        try {
            DB::beginTransaction();

            $log = LegacySyncLog::create([
                'position_date' => $posDate,
                'status'        => 'running',
                'total_cases'   => count($caseIds),
                'message'       => 'Legacy Sync sedang diproses (batch).',
                'run_by'        => auth()->id(),
                'failed_cases'  => 0,
            ]);

            $run = LegacySyncRun::create([
                'posisi_date' => $posDate,
                'total'       => count($caseIds),
                'processed'   => 0,
                'failed'      => 0,
                'status'      => LegacySyncRun::STATUS_RUNNING,
                'started_at'  => now(),
                'created_by'  => auth()->id(),
            ]);

            // OPTIONAL tapi sangat membantu: kalau tabel legacy_sync_logs punya kolom run_id
            // $log->update(['run_id' => $run->id]);

            $jobs = array_map(fn ($id) => new SyncLegacySpForCaseJob($id, $run->id), $caseIds);

            $pending = Bus::batch($jobs)
                ->name("LegacySync:{$posDate}")
                ->onQueue('crms')
                ->then(function (Batch $batch) use ($log, $run) {
                    $log->update([
                        'status'  => 'success',
                        'message' => 'Legacy Sync selesai.',
                    ]);

                    $run->update([
                        'status'      => LegacySyncRun::STATUS_DONE,
                        'finished_at' => now(),
                    ]);
                })
                ->catch(function (Batch $batch, \Throwable $e) use ($log, $run) {
                    $log->update([
                        'status'  => 'failed',
                        'message' => 'Legacy Sync gagal: ' . $e->getMessage(),
                    ]);

                    $run->update([
                        'status'      => LegacySyncRun::STATUS_FAILED,
                        'finished_at' => now(),
                    ]);
                })
                ->finally(function (Batch $batch) use ($log, $run) {
                    if ($batch->failedJobs > 0) {
                        $log->update(['failed_cases' => (int) $batch->failedJobs]);
                        $run->incrementFailed((int) $batch->failedJobs);
                    }
                });

            $batch = $pending->dispatch();

            $log->update(['batch_id' => $batch->id]);

            DB::commit();

            return back()->with('status', "Legacy Sync posisi {$posDate} sedang diproses.");
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', "Gagal menjalankan Legacy Sync: " . $e->getMessage());
        }
    }

    public function updateJadwal(Request $request)
    {
        $posDate = Carbon::parse($request->input('position_date'))->toDateString();

        $importOk = ImportLog::query()
            ->where('module','loans')
            ->where('status','success')
            ->whereDate('position_date', $posDate)
            ->exists();

        if (!$importOk) {
            return back()->with('error', "Step 1 belum sukses untuk posisi {$posDate}. Lakukan Import dulu.");
        }

        $legacyOk = LegacySyncLog::query()
            ->whereDate('position_date', $posDate)
            ->where('status','success')
            ->latest('id')
            ->exists();

        if (!$legacyOk) {
            return back()->with('error', "Step 2 (Legacy Sync) belum sukses untuk posisi {$posDate}.");
        }

        $caseIds = NplCase::query()
            ->where('status','open')
            ->whereHas('loanAccount', fn($q) => $q->whereDate('position_date', $posDate))
            ->pluck('id')
            ->map(fn($v)=>(int)$v)
            ->all();

        if (empty($caseIds)) {
            return back()->with('error', "Tidak ada case OPEN pada posisi {$posDate}.");
        }

        $log = ScheduleUpdateLog::create([
            'position_date' => $posDate,
            'status'        => 'running',
            'total_cases'   => count($caseIds),
            'message'       => 'Update Jadwal sedang diproses (batch).',
            'run_by'        => auth()->id(),
            'failed_cases'  => 0,
        ]);

        $jobs = array_map(fn($id) => new RebuildSpSchedulesForCaseJob($id), $caseIds);

        $batch = Bus::batch($jobs)
            ->name("UpdateJadwal:{$posDate}")
            ->onQueue('crms')
            ->then(function (Batch $batch) use ($log) {
                $log->update([
                    'status'  => 'success',
                    'message' => 'Update Jadwal selesai.',
                ]);
            })
            ->catch(function (Batch $batch, \Throwable $e) use ($log) {
                $log->update([
                    'status'  => 'failed',
                    'message' => 'Update Jadwal gagal: '.$e->getMessage(),
                ]);
            })
            ->finally(function (Batch $batch) use ($log) {
                if ($batch->failedJobs > 0) {
                    $log->update(['failed_cases' => (int)$batch->failedJobs]);
                }
            })
            ->dispatch();

        $log->update(['batch_id' => $batch->id]);

        return back()->with('status', "Update Jadwal posisi {$posDate} sedang diproses.");
    }

    // =========================================================
    // ✅ STATUS ENDPOINTS untuk POLLING (Step 2 & 3)
    // =========================================================

    public function legacySyncStatus(Request $request)
    {
        $posDate = $request->query('position_date');
        if (empty($posDate)) {
            return response()->json(['found' => false, 'message' => 'position_date is required'], 422);
        }

        $posDate = Carbon::parse($posDate)->toDateString();

        $log = LegacySyncLog::query()
            ->whereDate('position_date', $posDate)
            ->latest('id')
            ->first();

        if (!$log) {
            return response()->json(['found' => false]);
        }

        $payload = $this->hydrateLogWithBatchProgress($log);

        return response()->json($payload);
    }

    public function updateJadwalStatus(Request $request)
    {
        $posDate = $request->query('position_date');
        if (empty($posDate)) {
            return response()->json(['found' => false, 'message' => 'position_date is required'], 422);
        }

        $posDate = Carbon::parse($posDate)->toDateString();

        $log = ScheduleUpdateLog::query()
            ->whereDate('position_date', $posDate)
            ->latest('id')
            ->first();

        if (!$log) {
            return response()->json(['found' => false]);
        }

        $payload = $this->hydrateLogWithBatchProgress($log);

        return response()->json($payload);
    }

    /**
     * Ambil progress dari job_batches, dan auto-finalize kalau batch sudah selesai
     * tapi log masih "running".
     */
    private function hydrateLogWithBatchProgress($log): array
    {
        $status  = (string)($log->status ?? '');
        $message = (string)($log->message ?? '');
        $batchId = (string)($log->batch_id ?? '');

        $progress = null;

        // 🔹 INI DIA TEMPATNYA (yang kamu tanyakan)
        $run = LegacySyncRun::query()
            ->whereDate('posisi_date', (string)$log->position_date)
            ->latest('id')
            ->first();

        // =========================
        // UTAMA: ambil dari job batch
        // =========================
        if ($batchId !== '') {
            $batch = Bus::findBatch($batchId);

            if ($batch) {
                $progress = $this->buildBatchProgress($batch);
            }
        }

        // =========================
        // FALLBACK: kalau batch null / kosong
        // =========================
        if (!$progress && $run) {
            $total     = (int) $run->total;
            $processed = (int) $run->processed;
            $failed    = (int) $run->failed;

            $percent = $total > 0 ? (int) floor(($processed / $total) * 100) : 0;

            $progress = [
                'total'     => $total,
                'processed' => $processed,
                'pending'   => max(0, $total - $processed),
                'failed'    => $failed,
                'percent'   => $percent,
                'note'      => 'Progress dari legacy_sync_runs',
            ];
        }

        return [
            'found'    => true,
            'status'   => $status,
            'message'  => $message,
            'progress' => $progress,
        ];
    }

    private function buildBatchProgress(Batch $batch): array
    {
        $total     = (int)$batch->totalJobs;
        $pending   = (int)$batch->pendingJobs;
        $failed    = (int)$batch->failedJobs;

        // processed = total - pending
        $processed = max(0, $total - $pending);

        $percent = $total > 0 ? (int)floor(($processed / $total) * 100) : 0;

        // info tambahan (opsional untuk UI)
        $note = null;
        if ($batch->cancelled()) $note = 'Batch dibatalkan.';
        elseif ($batch->finished()) $note = 'Batch selesai.';
        else $note = 'Batch sedang berjalan.';

        return [
            'total'     => $total,
            'processed' => $processed,
            'pending'   => $pending,
            'failed'    => $failed,
            'percent'   => $percent,
            'note'      => $note,
        ];
    }

    public function importInstallments(Request $request)
    {
        @ini_set('max_execution_time', '600');
        @set_time_limit(600);
        @ini_set('memory_limit', '1024M');

        $validated = $request->validate([
            'file_installments' => 'required|file|max:20480|mimes:xls,xlsx',
            'position_date'     => ['required', 'date'],
            'reimport'          => ['nullable', 'boolean'],
            'reimport_reason'   => ['nullable', 'string', 'max:2000'],
        ]);

        $posDate  = Carbon::parse($validated['position_date'])->toDateString();
        $file     = $request->file('file_installments');
        $fileName = $file?->getClientOriginalName();

        $reimport = (bool)($validated['reimport'] ?? false);
        $reason   = trim((string)($validated['reimport_reason'] ?? ''));

        // ✅ cegah double success posisi sama (strict) kecuali reimport
        $exists = ImportLog::query()
            ->where('module', 'installments')
            ->where('status', 'success')
            ->whereDate('position_date', $posDate)
            ->exists();

        if ($exists && !$reimport) {
            return back()
                ->withErrors(['position_date' => "Installment posisi {$posDate} sudah pernah diimport (SUCCESS). Jika koreksi, gunakan Re-import."])
                ->withInput();
        }

        if ($exists && $reimport && $reason === '') {
            return back()
                ->withErrors(['reimport_reason' => 'Alasan re-import wajib diisi.'])
                ->withInput();
        }

        // ✅ gunakan sourceFile supaya tercatat di loan_installments.source_file
        // kalau constructor kamu mendukung parameter ke-3, pakai ini:
        // $importer = new LoanInstallmentsImport($posDate, null, $fileName);
        $importer = new LoanInstallmentsImport($posDate);

        try {
            Excel::import($importer, $file);

            $s = (array) $importer->summary();

            // ✅ HARDEN: fallback keys (biar gak ada Undefined index lagi)
            $total    = (int)($s['total'] ?? 0);

            // inserted/updated dari importer baru
            $inserted = (int)($s['inserted'] ?? 0);
            $updated  = (int)($s['updated'] ?? 0);

            // fallback untuk importer lama yang hanya punya upserted
            $upserted = (int)($s['upserted'] ?? 0);
            if ($inserted === 0 && $updated === 0 && $upserted > 0) {
                // kita gak bisa bedain inserted vs updated, tapi minimal gak bikin angka 0 semua
                $inserted = $upserted;
                $updated  = 0;
            }

            $skipped  = (int)($s['skipped'] ?? 0);

            ImportLog::create([
                'module'        => 'installments',
                'position_date' => $posDate,
                'run_type'      => $reimport ? 'reimport' : 'import',
                'file_name'     => $fileName,
                'rows_total'    => $total,
                'rows_inserted' => $inserted,
                'rows_updated'  => $updated,
                'rows_skipped'  => $skipped,
                'status'        => 'success',
                'message'       => ($reimport ? 'RE-IMPORT' : 'IMPORT')
                    . " installment sukses | total={$total} inserted={$inserted} updated={$updated} skipped={$skipped}",
                'reason'        => $reimport ? $reason : null,
                'imported_by'   => auth()->id(),
            ]);

           return redirect()
                ->route('loans.import.form')
                ->with([
                    // ✅ tetap pertahankan global banner
                    'status' => "Import installment selesai. Posisi: {$posDate}. Total: {$total} | Inserted: {$inserted} | Updated: {$updated} | Skipped: {$skipped}",

                    // ✅ ini yg dibaca box Step 1B
                    'installments_status'  => 'success',
                    'installments_message' => "Posisi: {$posDate}\nTotal: {$total}\nInserted: {$inserted}\nUpdated: {$updated}\nSkipped: {$skipped}",

                    // optional (kalau kamu mau tampilkan)
                    'installments_errors'  => method_exists($importer, 'errors') ? $importer->errors() : [],
                ]);

        } catch (ExcelValidationException $e) {

            Log::error('[IMPORT INSTALLMENTS] ExcelValidationException', [
                'pos_date' => $posDate,
                'file'     => $fileName,
                'failures' => $e->failures(),
            ]);

            ImportLog::create([
                'module'        => 'installments',
                'position_date' => $posDate,
                'run_type'      => $reimport ? 'reimport' : 'import',
                'file_name'     => $fileName,
                'status'        => 'failed',
                'message'       => 'Header/kolom installment tidak sesuai template.',
                'reason'        => $reimport ? $reason : null,
                'imported_by'   => auth()->id(),
            ]);

            return back()
                ->withInput()
                ->with([
                    'error' => 'Gagal import installment: header/kolom tidak sesuai template.',

                    // ✅ box Step 1B
                    'installments_status'  => 'failed',
                    'installments_message' => 'Header/kolom installment tidak sesuai template.',
                    'installments_errors'  => [], // kalau mau, bisa isi failure ringkas
                ]);

        } catch (\Throwable $e) {

            Log::error('[IMPORT INSTALLMENTS] Throwable', [
                'pos_date' => $posDate,
                'file'     => $fileName,
                'msg'      => $e->getMessage(),
                'file_at'  => $e->getFile(),
                'line'     => $e->getLine(),
            ]);

            ImportLog::create([
                'module'        => 'installments',
                'position_date' => $posDate,
                'run_type'      => $reimport ? 'reimport' : 'import',
                'file_name'     => $fileName,
                'status'        => 'failed',
                'message'       => 'Import installment gagal: ' . $e->getMessage(),
                'reason'        => $reimport ? $reason : null,
                'imported_by'   => auth()->id(),
            ]);

            return back()
                ->withInput()
                ->with([
                    'error' => 'Gagal import installment. Detail ada di log.',

                    // ✅ box Step 1B
                    'installments_status'  => 'failed',
                    'installments_message' => $e->getMessage(), // atau versi aman: 'Terjadi error saat proses import.'
                    'installments_errors'  => [],
                ]);
        }
    }

    private function humanizeImportError(\Throwable $e): string
    {
        $m = (string) $e->getMessage();

        if (stripos($m, 'Tidak ada data terbaca') !== false) {
            return 'Header kolom tidak sesuai template (data tidak terbaca). Pastikan header persis sama.';
        }

        if (stripos($m, 'Unknown column') !== false) {
            return 'Kolom database tidak ditemukan. Kemungkinan migration/field baru belum ada. Detail: '.$m;
        }

        if (stripos($m, 'Duplicate entry') !== false) {
            return 'Data duplikat (unique key). Detail: '.$m;
        }

        return $m !== '' ? $m : 'Terjadi kesalahan saat import. Silakan cek log.';
    }

    
    public function importClosures(Request $request)
    {
        $request->validate([
            'file_pelunasan' => ['required','file','mimes:xls,xlsx'],
        ]);

        try {

            $file = $request->file('file_pelunasan');

            $import = new LoanAccountClosuresImport(
                $file->getClientOriginalName()
            );

            Excel::import($import, $file);

            return redirect()
                ->route('loans.import.form') // lebih aman daripada back()
                ->with([
                    'pelunasan_status'   => 'success',
                    'pelunasan_message'  =>
                        "Inserted={$import->inserted}, Updated={$import->updated}, Skipped={$import->skipped}",
                    'pelunasan_errors'   => $import->errors ?? [],
                    'pelunasan_batch_id' => $import->batchId ?? null,
                ]);

        } catch (\Throwable $e) {

            \Log::error('[IMPORT PELUNASAN ERROR]', [
                'msg' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return redirect()
                ->route('loans.import.form')
                ->with([
                    'pelunasan_status'  => 'failed',
                    'pelunasan_message' => 'Import gagal. Detail ada di log.',
                ]);
        }
    }
    
}
