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

        return view('loans.import', compact('lastImport', 'lastLegacy', 'lastSchedule'));
    }

    public function legacySync(Request $request)
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

        $caseIds = NplCase::query()
            ->where('status','open')
            ->whereHas('loanAccount', fn($q) => $q->whereDate('position_date', $posDate))
            ->pluck('id')
            ->map(fn($v)=>(int)$v)
            ->all();

        if (empty($caseIds)) {
            return back()->with('error', "Tidak ada case OPEN pada posisi {$posDate}.");
        }

        $log = LegacySyncLog::create([
            'position_date' => $posDate,
            'status'        => 'running',
            'total_cases'   => count($caseIds),
            'message'       => 'Legacy Sync sedang diproses (batch).',
            'run_by'        => auth()->id(),
            'failed_cases'  => 0,
        ]);

        $jobs = array_map(fn($id) => new SyncLegacySpForCaseJob($id), $caseIds);

        $batch = Bus::batch($jobs)
            ->name("LegacySync:{$posDate}")
            ->onQueue('crms')
            ->then(function (Batch $batch) use ($log) {
                $log->update([
                    'status'  => 'success',
                    'message' => 'Legacy Sync selesai.',
                ]);
            })
            ->catch(function (Batch $batch, \Throwable $e) use ($log) {
                $log->update([
                    'status'  => 'failed',
                    'message' => 'Legacy Sync gagal: '.$e->getMessage(),
                ]);
            })
            ->finally(function (Batch $batch) use ($log) {
                if ($batch->failedJobs > 0) {
                    $log->update(['failed_cases' => (int)$batch->failedJobs]);
                }
            })
            ->dispatch();

        $log->update(['batch_id' => $batch->id]);

        return back()->with('status', "Legacy Sync posisi {$posDate} sedang diproses.");
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

        if ($batchId !== '') {
            $batch = Bus::findBatch($batchId);

            if ($batch) {
                $progress = $this->buildBatchProgress($batch);

                // ✅ AUTO-FINALIZE (anti "RUNNING selamanya")
                if (strtolower($status) === 'running' && $batch->finished()) {
                    $finalStatus = $batch->failedJobs > 0 ? 'failed' : 'success';
                    $finalMsg    = $finalStatus === 'success'
                        ? 'Selesai (batch finished).'
                        : 'Selesai dengan error (ada job gagal).';

                    try {
                        $log->update([
                            'status'       => $finalStatus,
                            'message'      => $finalMsg,
                            'failed_cases' => (int)$batch->failedJobs,
                        ]);
                        $status  = $finalStatus;
                        $message = $finalMsg;
                    } catch (\Throwable $e) {
                        // kalau update gagal, minimal endpoint tetap kasih progress
                    }
                }
            } else {
                // batch_id ada tapi record batch hilang / belum ada tabel job_batches
                $progress = [
                    'total'     => (int)($log->total_cases ?? 0),
                    'processed' => 0,
                    'pending'   => (int)($log->total_cases ?? 0),
                    'failed'    => (int)($log->failed_cases ?? 0),
                    'percent'   => 0,
                    'note'      => 'Batch tidak ditemukan (cek job_batches).',
                ];
            }
        }

        return [
            'found'         => true,
            'position_date' => (string)$log->position_date,
            'status'        => $status,
            'message'       => $message,
            'batch_id'      => $batchId ?: null,
            'progress'      => $progress,
            'updated_at'    => optional($log->updated_at)->toDateTimeString(),
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

}
