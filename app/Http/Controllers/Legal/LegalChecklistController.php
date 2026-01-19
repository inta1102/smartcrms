<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Models\LegalAction;
use App\Models\LegalAdminChecklist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LegalChecklistController extends Controller
{
    public function save(Request $request, LegalAction $action)
    {
        // ✅ minimal: user memang boleh update action ini (ikut Policy LegalAction)
        $this->authorize('update', $action);

        // ✅ (opsional tapi recommended) checklist terkunci pada status tertentu
        // Contoh: setelah SUBMITTED ke KPKNL, checklist tidak boleh diubah
        $status = strtolower((string) $action->status);
        if (in_array($status, ['submitted','scheduled','executed','settled','closed','cancelled'], true)) {
            abort(403, 'Checklist terkunci karena status HT sudah lanjut.');
        }

        $data = $request->validate([
            'items'              => ['required','array','min:1'],
            'items.*.id'         => ['required','integer'],
            'items.*.is_checked' => ['required','boolean'],
            'items.*.notes'      => ['nullable','string','max:2000'],
        ]);

        $ids = collect($data['items'])
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values();

        // ✅ ambil semua checklist milik action ini sekali saja
        $items = LegalAdminChecklist::query()
            ->with('legalAction') // supaya Policy(toggle) bisa akses legalAction tanpa query tambahan
            ->where('legal_action_id', $action->id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        // ✅ pastikan tidak ada id "nyasar"
        if ($items->count() !== $ids->count()) {
            abort(404, 'Ada checklist item yang tidak ditemukan untuk action ini.');
        }

        DB::transaction(function () use ($data, $action) {
            foreach ($data['items'] as $row) {
                $id = (int) $row['id'];

                /** @var LegalAdminChecklist $item */
                $item = LegalAdminChecklist::query()
                    ->where('legal_action_id', $action->id)
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // ✅ OTORISASI PER-ITEM:
                // - TL bisa checklist bawahan
                // - KASI (mis: Helmi) juga bisa checklist kalau dalam scope supervisinya
                // (atur di LegalAdminChecklistPolicy@toggle)
                $this->authorize('toggle', $item);

                $isChecked = (bool) $row['is_checked'];

                $item->is_checked = $isChecked;
                $item->notes      = $row['notes'] ?? null;

                if ($isChecked) {
                    // ✅ dicentang → set checker (kalau sudah pernah dicentang, override jadi user yang terakhir checklist)
                    $item->checked_by = auth()->id();
                    $item->checked_at = now();
                } else {
                    // ✅ di-uncheck → kosongkan biar bisa dicentang ulang
                    $item->checked_by = null;
                    $item->checked_at = null;
                }

                $item->save();
            }
        });

        return back()->with('success', 'Checklist administratif tersimpan.');
    }
}
