<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Models\LegalAction;
use App\Models\LegalEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LegalEventController extends Controller
{
    private function assertBelongs(LegalAction $action, LegalEvent $event): void
    {
        abort_unless((int) $event->legal_action_id === (int) $action->id, 404);
    }

    private function guardActionNotFinal(LegalAction $action): void
    {
        $st = strtolower((string) $action->status);
        if (in_array($st, ['closed','cancelled'], true)) {
            abort(403, 'Legal Action sudah final. Event tidak bisa diubah.');
        }
    }

    public function markDone(LegalAction $action, LegalEvent $event)
    {
        $this->authorize('update', $action);
        $this->assertBelongs($action, $event);
        $this->guardActionNotFinal($action);

        DB::transaction(function () use ($event) {
            $ev = LegalEvent::whereKey($event->id)->lockForUpdate()->first();
            if (!$ev) return;

            if ($ev->status !== 'scheduled') return;

            $ev->status    = 'done';
            $ev->remind_at = null; // stop reminder
            $ev->save();
        });

        return redirect()
            ->to(route('legal-actions.show', $action).'?tab=events')
            ->with('success', 'Event ditandai selesai.');
    }

    public function cancel(LegalAction $action, LegalEvent $event)
    {
        $this->authorize('update', $action);
        $this->assertBelongs($action, $event);
        $this->guardActionNotFinal($action);

        DB::transaction(function () use ($event) {
            $ev = LegalEvent::whereKey($event->id)->lockForUpdate()->first();
            if (!$ev) return;

            if ($ev->status !== 'scheduled') return;

            $ev->status    = 'cancelled';
            $ev->remind_at = null; // stop reminder
            $ev->save();
        });

        return redirect()
            ->to(route('legal-actions.show', $action).'?tab=events')
            ->with('success', 'Event dibatalkan.');
    }

    public function reschedule(Request $request, LegalAction $action, LegalEvent $event)
    {
        $this->authorize('update', $action);
        $this->assertBelongs($action, $event);
        $this->guardActionNotFinal($action);

        $validated = $request->validate([
            'event_at'  => ['required', 'date'],
            'notes'     => ['nullable', 'string', 'max:4000'],
            'remind_at' => ['nullable', 'date'],
        ]);

        $newEventAt = Carbon::parse($validated['event_at']);

        // server-side guard: remind_at tidak boleh >= event_at
        if (!empty($validated['remind_at'])) {
            $ra = Carbon::parse($validated['remind_at']);
            if ($ra->greaterThanOrEqualTo($newEventAt)) {
                return back()->withErrors([
                    'remind_at' => 'Reminder harus lebih awal daripada waktu event.'
                ])->withInput();
            }
        }

        DB::transaction(function () use ($validated, $event, $newEventAt) {
            $ev = LegalEvent::whereKey($event->id)->lockForUpdate()->first();
            if (!$ev) return;

            // hanya scheduled yang boleh di-reschedule
            if ($ev->status !== 'scheduled') return;

            $ev->event_at = $newEventAt;

            // remind_at manual atau otomatis
            if (!empty($validated['remind_at'])) {
                $ev->remind_at = Carbon::parse($validated['remind_at']);
            } else {
                $ev->remind_at = $this->autoRemindAt((string) $ev->event_type, $newEventAt);
            }

            // pastikan remind_at tetap sebelum event_at (antisipasi autoRemindAt)
            if ($ev->remind_at && Carbon::parse($ev->remind_at)->greaterThanOrEqualTo($newEventAt)) {
                $ev->remind_at = $newEventAt->copy()->subHours(2);
            }

            // reset flag reminder terkirim
            $ev->reminded_at = null;
            $ev->reminded_by = null;

            // notes: izinkan user mengosongkan (kalau field dikirim)
            if (array_key_exists('notes', $validated)) {
                $ev->notes = $validated['notes'] !== null ? trim((string)$validated['notes']) : null;
            }

            $ev->save();
        });

        return redirect()
            ->to(route('legal-actions.show', $action).'?tab=events')
            ->with('success', 'Event berhasil dijadwalkan ulang.');
    }

    private function autoRemindAt(string $eventType, Carbon $eventAt): Carbon
    {
        $eventType = strtolower(trim($eventType));

        // follow_up: 2 jam sebelum
        if ($eventType === 'follow_up') {
            return $eventAt->copy()->subHours(2);
        }

        // default: H-1 jam 09:00, tapi jangan sampai melewati event_at
        $h1 = $eventAt->copy()->subDay()->setTime(9, 0, 0);

        // kalau event-nya pagi, H-1 09:00 bisa "terlalu dekat" atau malah setelah event
        // fallback aman: 2 jam sebelum event
        if ($h1->greaterThanOrEqualTo($eventAt)) {
            return $eventAt->copy()->subHours(2);
        }

        return $h1;
    }
}
