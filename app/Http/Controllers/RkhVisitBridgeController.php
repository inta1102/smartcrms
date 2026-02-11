<?php

// app/Http/Controllers/RkhVisitBridgeController.php
namespace App\Http\Controllers;

use App\Models\RkhDetail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use App\Models\VisitSchedule;
use App\Models\LoanAccount;
use App\Models\NplCase;
use App\Models\ActionSchedule;


class RkhVisitBridgeController extends Controller
{

    public function start(int $detailId): RedirectResponse
    {
        $detail = RkhDetail::with('header')->findOrFail($detailId);
        $notes  = trim((string) request()->input('notes', ''));

        // kalau sudah ada
        if (!empty($detail->visit_schedule_id)) {
            return redirect()->route('visitSchedules.start', $detail->visit_schedule_id);
        }

        // header WAJIB ada
        if (!$detail->header) {
            abort(500, 'RKH header tidak ditemukan untuk detail ini.');
        }

        $scheduledAt = Carbon::parse($detail->header->tanggal)
            ->setTimeFromTimeString($detail->jam_mulai);

        $schedule = VisitSchedule::create([
            'rkh_detail_id' => $detail->id,
            'scheduled_at'  => $scheduledAt,
            'title'         => 'LKH RKH: ' . ($detail->nama_nasabah ?? '-'),
            'notes'         => $notes !== '' ? $notes : null,
            'status'        => 'planned',
        ]);

        $detail->visit_schedule_id = $schedule->id;
        $detail->save();

        return redirect()->route('visitSchedules.start', $schedule->id);
    }

    private function ensureCaseByAccountNo(string $accountNo): NplCase
    {
        $accountNo = trim($accountNo);

        $loan = LoanAccount::query()
            ->where('account_no', $accountNo)
            ->firstOrFail();

        // 1 loan_account => 1 open case (preferred)
        $case = NplCase::query()
            ->where('loan_account_id', $loan->id)
            ->where('status', 'open')
            ->whereNull('closed_at')
            ->first();

        if ($case) return $case;

        // bikin light-case (container timeline)
        return NplCase::create([
            'loan_account_id' => $loan->id,
            'pic_user_id'     => auth()->id(), // nullable di table, tapi bagus diisi
            'status'          => 'open',
            'priority'        => 'normal',
            'opened_at'       => now()->toDateString(),
            'summary'         => 'Auto-created from RKH visit',
        ]);
    }


    private function ensureVisitScheduleFromRkhDetail($detail, int $caseId, \Carbon\Carbon $scheduledAt): ActionSchedule
    {
        // kalau sudah ada schedule pending/in_progress untuk detail ini, pakai itu
        $existing = ActionSchedule::query()
            ->where('type', 'visit')
            ->whereIn('status', ['pending','in_progress'])
            ->where('source_system', 'rkh')
            ->where('source_ref_id', $detail->id)
            ->first();

        if ($existing) {
            // sync jadwal (opsional)
            $existing->scheduled_at = $scheduledAt;
            $existing->save();
            return $existing;
        }

        return ActionSchedule::create([
            'npl_case_id'   => $caseId,
            'type'          => 'visit',
            'title'         => 'Kunjungan Lapangan',
            'notes'         => 'LKH RKH: ' . ($detail->nama_nasabah ?? '-'),
            'scheduled_at'  => $scheduledAt,
            'status'        => 'pending',
            'created_by'    => auth()->id(),
            'source_system' => 'rkh',
            'source_ref_id' => $detail->id,
        ]);
    }

}
