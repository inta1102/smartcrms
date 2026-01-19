<?php

namespace App\Http\Controllers;

use App\Models\ActionSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

class ActionScheduleController extends Controller
{
    /**
     * Mark schedule as completed.
     *
     * Catatan:
     * - Idealnya pakai Policy: ActionSchedulePolicy@complete
     * - Idempotent + lockForUpdate biar aman dari double click.
     */

    public function complete(Request $request, ActionSchedule $schedule)
    {
        // ✅ Authorization (aktifkan kalau policy sudah siap)
        // $this->authorize('complete', $schedule);

        $validated = $request->validate([
            'notes'        => ['nullable', 'string', 'max:2000'],
            'completed_at' => ['nullable', 'date'], // kalau kosong pakai now()
        ]);

        $userId = auth()->id();

        DB::transaction(function () use ($schedule, $validated, $userId) {

            // Lock row biar aman dari race condition
            $sch = ActionSchedule::whereKey($schedule->id)->lockForUpdate()->first();

            if (!$sch) {
                abort(404);
            }

            // ✅ Izinkan complete dari pending atau escalated
            $allowedStatuses = ['pending', 'escalated'];

            // Kalau sudah done/cancelled, idempotent: tidak ubah
            if (!in_array(($sch->status ?? null), $allowedStatuses, true)) {
                return;
            }

            $sch->status = 'done';

            $sch->completed_at = !empty($validated['completed_at'])
                ? Carbon::parse($validated['completed_at'])
                : now();

            // ✅ notes (kalau kolom ada)
            if (!empty($validated['notes']) && Schema::hasColumn($sch->getTable(), 'notes')) {
                $sch->notes = trim((string) $validated['notes']);
            }

            // ✅ completed_by (kalau kolom ada)
            if ($userId && Schema::hasColumn($sch->getTable(), 'completed_by')) {
                $sch->completed_by = $userId;
            }

            // ✅ optional: kalau ada kolom audited "updated_by"
            if ($userId && Schema::hasColumn($sch->getTable(), 'updated_by')) {
                $sch->updated_by = $userId;
            }

            $sch->save();
        });

        $schedule->refresh();

        if (($schedule->status ?? null) !== 'done') {
            return back()->with('error', 'Agenda ini sudah tidak berstatus pending/escalated.');
        }

        return back()->with('success', 'Agenda ditandai selesai.');
    }
}
