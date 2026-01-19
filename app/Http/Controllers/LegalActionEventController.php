<?php

namespace App\Http\Controllers;

use App\Models\LegalAction;
use App\Models\LegalEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LegalActionEventController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function markSomasiReceived(Request $request, LegalAction $action)
    {
        // pastikan memang somasi
        if (($action->action_type ?? '') !== 'somasi') {
            abort(404);
        }

        // ✅ 1 pintu role (paling aman):
        // user yang boleh update action, boleh catat event.
        // Kalau kamu mau lebih ketat: bikin policy khusus "recordEvent".
        $this->authorize('update', $action);

        $validated = $request->validate([
            'received_at' => ['required', 'date'],
            'notes'       => ['nullable', 'string', 'max:2000'],
            'location'    => ['nullable', 'string', 'max:255'],
        ]);

        $receivedAt = Carbon::parse($validated['received_at']);

        DB::transaction(function () use ($action, $validated, $receivedAt) {

            // idempotent: kalau sudah ada event received, update (bukan bikin dobel)
            $event = LegalEvent::query()
                ->where('legal_action_id', $action->id)
                ->where('event_type', 'somasi_received')
                ->first();

            $payload = [
                'legal_case_id'   => $action->legal_case_id,
                'legal_action_id' => $action->id,

                'event_type' => 'somasi_received',
                'title'      => 'Somasi diterima debitur',
                'event_at'   => $receivedAt,

                'location' => $validated['location'] ?? null,
                'notes'    => $validated['notes'] ?? null,

                'status' => 'done',
            ];

            if (!$event) {
                // created_by hanya saat create
                $payload['created_by'] = auth()->id();
                LegalEvent::create($payload);
            } else {
                $event->fill($payload);

                // kalau tabel punya updated_by, isi di sini (opsional)
                if ($event->isFillable('updated_by')) {
                    $event->updated_by = auth()->id();
                }

                $event->save();
            }

            // (Opsional) sinkron meta somasi di LegalAction supaya progress lebih konsisten
            // Kalau kamu sudah pakai meta['somasi']['received_at'], ini sangat membantu.
            $meta = (array) ($action->meta ?? []);
            $somasi = (array) ($meta['somasi'] ?? []);
            $somasi['receipt_status'] = 'received';
            $somasi['received_at']    = $receivedAt->toDateTimeString();

            $meta['somasi'] = $somasi;
            $action->meta = $meta;
            $action->save();
        });

        return back()->with('success', 'Event "Somasi Diterima" berhasil disimpan.');
    }
}
