<?php

namespace App\Http\Controllers;

use App\Models\NplCase;
use App\Models\CaseAction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CaseSpLegacyController extends Controller
{
    private const STEPS = ['spak','sp1','sp2','sp3','spt','spjad'];

    /**
     * (Opsional tapi recommended) aturan urutan issue:
     * spak -> sp1 -> sp2 -> sp3 -> spt -> spjad
     */
    private const ORDER = [
        'spak'  => null,
        'sp1'   => 'spak',
        'sp2'   => 'sp1',
        'sp3'   => 'sp2',
        'spt'   => 'sp3',
        'spjad' => 'spt',
    ];

    private function normalizeType(string $type): string
    {
        $t = strtolower(trim($type));
        abort_unless(in_array($t, self::STEPS, true), 404);
        return $t;
    }

    private function stepLabel(string $type): string
    {
        return match (strtolower($type)) {
            'spak'  => 'SPAK',
            'spjad' => 'SPJAD',
            'spt'   => 'SPT',
            default => strtoupper($type), // SP1, SP2, SP3
        };
    }

    /**
     * ✅ 1 pintu akses untuk endpoint yang mengubah data
     * (kamu bisa ganti ke policy method yang lebih spesifik kalau mau)
     */
    private function ensureCanUpdate(NplCase $case): void
    {
        $this->authorize('update', $case);
    }

    private function getMetaArray(?CaseAction $action): array
    {
        $m = $action?->meta;

        // Kalau meta sudah di-cast array, ini biasanya sudah array.
        if (is_string($m)) $m = json_decode($m, true);

        return is_array($m) ? $m : [];
    }

    private function upsertSpMeta(CaseAction $action, array $spPatch): void
    {
        $meta = $this->getMetaArray($action);
        $meta['sp'] = array_merge($meta['sp'] ?? [], $spPatch);
        $action->meta = $meta;
    }

    private function findStepAction(NplCase $case, string $type): ?CaseAction
    {
        return $case->actions()
            ->where('source_system', 'legacy_sp')
            ->whereRaw('LOWER(action_type) = ?', [$type])
            ->first();
    }

    /**
     * (Opsional) cegah loncat step.
     * Kalau kamu tidak mau, tinggal comment pemanggilan di issue().
     */
    private function assertPreviousIssued(NplCase $case, string $type): void
    {
        $prev = self::ORDER[$type] ?? null;
        if (!$prev) return;

        $prevAction = $this->findStepAction($case, $prev);
        if (!$prevAction) {
            abort(422, "Tidak bisa issue {$this->stepLabel($type)} karena {$this->stepLabel($prev)} belum ada.");
        }
    }

    // Optional: halaman khusus, tapi bisa juga langsung di show.blade
    public function index(NplCase $case)
    {
        $this->authorize('view', $case);
        return redirect()->route('cases.show', $case);
    }

    public function issue(Request $request, NplCase $case, string $type)
    {
        $this->authorize('view', $case);
        $this->ensureCanUpdate($case);

        $type = $this->normalizeType($type);

        // (Opsional) urutan
        $this->assertPreviousIssued($case, $type);

        $data = $request->validate([
            'letter_no' => ['required', 'string', 'max:100'],
            'issued_at' => ['required', 'date'],
            'notes'     => ['nullable', 'string', 'max:2000'],
        ]);

        $issuedAt = Carbon::parse($data['issued_at']);
        $dueAt    = (clone $issuedAt)->addDays(7);

        DB::transaction(function () use ($case, $type, $data, $issuedAt, $dueAt) {

            $action = $this->findStepAction($case, $type);

            if (!$action) {
                $action = new CaseAction();
                $action->npl_case_id   = $case->id;
                $action->source_system = 'legacy_sp';
                $action->action_type   = $type;
            }

            $label = $this->stepLabel($type);

            $action->action_at   = $issuedAt;
            $action->result      = 'ISSUED';
            $action->description = "{$label} dibuat di Legacy. No: {$data['letter_no']}";

            // ✅ auto target follow-up 7 hari
            $action->next_action     = "Follow-up {$label} (cek progress / respon debitur)";
            $action->next_action_due = $dueAt;

            $this->upsertSpMeta($action, [
                'status'    => 'issued',
                'letter_no' => $data['letter_no'],
                'issued_at' => $issuedAt->toDateTimeString(),
                'due_at'    => $dueAt->toDateTimeString(),
                'notes'     => $data['notes'] ?? null,
            ]);

            $action->save();
        });

        return back()->with('success', strtoupper($type) . ' berhasil di-ISSUE (Legacy).');
    }

    public function ship(Request $request, NplCase $case, string $type)
    {
        $this->authorize('view', $case);
        $this->ensureCanUpdate($case);

        $type = $this->normalizeType($type);

        $data = $request->validate([
            'delivery_method' => ['required', Rule::in(['pos','kurir','petugas_bank','kuasa_hukum','lainnya'])],
            'receipt_no'      => ['nullable', 'string', 'max:100'],
            'notes'           => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($case, $type, $data) {
            $action = $this->findStepAction($case, $type);
            abort_unless($action, 404);

            $label = $this->stepLabel($type);

            $action->result = 'SENT';
            $action->description = "{$label} dikirim. Metode: {$data['delivery_method']}"
                . ($data['receipt_no'] ? " • Resi: {$data['receipt_no']}" : '');

            // ✅ setelah sent: fokus tunggu diterima/POD/return
            // next_action_due tetap (deadline 7 hari dari issued)
            $action->next_action = "Menunggu diterima / POD / Return ({$label})";

            $this->upsertSpMeta($action, [
                'status'          => 'sent',
                'delivery_method' => $data['delivery_method'],
                'receipt_no'      => $data['receipt_no'] ?? null,
                'ship_notes'      => $data['notes'] ?? null,
            ]);

            $action->save();
        });

        return back()->with('success', strtoupper($type) . ' pengiriman tersimpan.');
    }

    public function finalize(Request $request, NplCase $case, string $type)
    {
        $this->authorize('view', $case);
        $this->ensureCanUpdate($case);

        $type = $this->normalizeType($type);

        $data = $request->validate([
            'final_status' => ['required', Rule::in(['received','returned','unknown','closed'])],
            'final_notes'  => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($case, $type, $data) {
            $action = $this->findStepAction($case, $type);
            abort_unless($action, 404);

            $label = $this->stepLabel($type);

            $final = $data['final_status'];

            $action->result = strtoupper($final);

            // ✅ final => step selesai, clear target
            $action->next_action     = null;
            $action->next_action_due = null;

            $action->description = "{$label} final: " . strtoupper($final)
                . (!empty($data['final_notes']) ? " • {$data['final_notes']}" : '');

            $this->upsertSpMeta($action, [
                'status'      => $final,
                'final_notes' => $data['final_notes'] ?? null,
            ]);

            $action->save();
        });

        return back()->with('success', strtoupper($type) . ' status akhir tersimpan.');
    }
}
