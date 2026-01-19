<?php

namespace App\Http\Controllers;

use App\Models\NplCase;
use App\Models\CaseResolutionTarget;
use App\Services\Crms\ResolutionTargetService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NplCaseResolutionTargetController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function store(Request $request, NplCase $case, ResolutionTargetService $svc)
    {
        // ✅ 1 pintu role: policy
        $this->authorize('propose', [CaseResolutionTarget::class, $case]);

        $data = $request->validate([
            'target_date'     => ['required', 'date', 'after_or_equal:today'],
            'strategy'        => ['nullable', 'string', 'max:30'],
            'reason'          => ['nullable', 'string', 'max:500'],
            'target_outcome'  => ['required', Rule::in(['lunas', 'lancar'])],
        ]);

        // normalisasi string
        foreach (['strategy', 'reason', 'target_outcome'] as $f) {
            if (array_key_exists($f, $data) && is_string($data[$f])) {
                $data[$f] = trim($data[$f]);
                if ($data[$f] === '') $data[$f] = null;
            }
        }

        // fallback logic (kalau service belum return model)
        $fallbackNeedsTl = $this->needsTlApprovalForUser((int) auth()->id());

        // Panggil service propose.
        // Catatan: jangan pakai named-arg baru seperti needsTlApproval karena bisa error
        // kalau signature svc belum ada. Jadi kita aman: panggil seperti sekarang.
        $result = $svc->propose(
            case: $case,
            targetDate: $data['target_date'],
            strategy: $data['strategy'] ?? null,
            proposedBy: auth()->id(),
            reason: $data['reason'] ?? null,
            targetOutcome: $data['target_outcome'],
        );

        // =========================
        // Tentukan message yang benar
        // =========================
        $msg = 'Target penyelesaian berhasil diajukan.';

        // Kalau service mengembalikan model CaseResolutionTarget,
        // kita baca status/flag real dari DB
        if ($result instanceof CaseResolutionTarget) {
            $status  = strtolower((string) ($result->status ?? ''));
            $needsTl = (bool) ($result->needs_tl_approval ?? false);

            if ($status === CaseResolutionTarget::STATUS_PENDING_TL) {
                $msg .= ' (Menunggu review TL)';
            } elseif ($status === CaseResolutionTarget::STATUS_PENDING_KASI) {
                $msg .= ' (Menunggu approval Kasi)';
            } elseif ($status === CaseResolutionTarget::STATUS_ACTIVE) {
                $msg .= ' (Sudah AKTIF)';
            } else {
                // fallback by flag
                $msg .= $needsTl ? ' (Menunggu review TL)' : ' (Menunggu approval Kasi)';
            }
        } else {
            // kalau service tidak return model, pakai fallback perhitungan org assignment
            $msg .= $fallbackNeedsTl ? ' (Menunggu review TL)' : ' (Menunggu approval Kasi)';
        }

        return back()->with('success', $msg);
    }

    public function storeByKti(Request $request, NplCase $case, ResolutionTargetService $svc)
    {
        // ✅ jalur khusus KTI
        $this->authorize('forceCreateByKti', [CaseResolutionTarget::class, $case]);

        $data = $request->validate([
            'target_date'     => ['required', 'date'], // KTI boleh input target masa lalu? kalau tidak, pakai after_or_equal:today
            'strategy'        => ['nullable', 'string', 'max:30'],
            'reason'          => ['nullable', 'string', 'max:500'],
            'target_outcome'  => ['required', Rule::in(['lunas', 'lancar'])],
        ]);

        foreach (['strategy', 'reason', 'target_outcome'] as $f) {
            if (array_key_exists($f, $data) && is_string($data[$f])) {
                $data[$f] = trim($data[$f]);
                if ($data[$f] === '') $data[$f] = null;
            }
        }

        $svc->forceActivateByKti(
            case: $case,
            targetDate: $data['target_date'],
            strategy: $data['strategy'] ?? null,
            inputBy: auth()->id(),
            reason: $data['reason'] ?? null,
            targetOutcome: $data['target_outcome'],
        );

        return back()->with('success', 'Target penyelesaian berhasil diinput oleh KTI dan langsung AKTIF.');
    }

    /**
     * Rule sederhana:
     * - kalau user punya leader_role TL/TLL/TLR => needs TL approval
     * - kalau tidak ada assignment => default aman: butuh TL (nanti di service bisa override ke pending_kasi sesuai logic final kamu)
     *
     * Catatan:
     * Ini hanya dipakai untuk fallback message / guard sederhana.
     * Routing status final tetap sebaiknya diputuskan di ResolutionTargetService.
     */
    protected function needsTlApprovalForUser(int $userId): bool
    {
        $oa = \App\Models\OrgAssignment::query()
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->first();

        if (!$oa) return true; // default aman: butuh TL

        return in_array(strtoupper((string)$oa->leader_role), ['TL', 'TLL', 'TLR'], true);
    }
}
