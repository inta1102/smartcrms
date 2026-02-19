<?php

namespace App\Http\Controllers\Supervision;

use App\Http\Controllers\Controller;
use App\Models\CaseResolutionTarget;
use App\Services\Crms\ResolutionTargetService;
use App\Services\Org\OrgVisibilityService;
use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Supervision\Concerns\EnsureKasiAccess;

class TargetApprovalKasiController extends Controller
{
    use EnsureKasiAccess;
    /**
     * Fallback levels (kalau suatu saat role belum enum).
     * Boleh kamu hapus kalau semua sudah pakai enum.
     */
    protected function kasiLevels(): array
    {
        $levels = (array) config('roles.kasi_levels', ['KSLU','KSLR','KSFE','KSBE','ksf','kso','ksa','ksr','kbl']);

        // normalize ke uppercase biar konsisten
        return array_values(array_unique(array_map(
            fn($x) => strtoupper(trim((string) $x)),
            $levels
        )));
    }

    protected function ensureKasiLevel(): void
    {
        $u = auth()->user();
        abort_unless($u, 403);

        // fallback string role/level
        $val = method_exists($u, 'roleValue')
            ? strtoupper((string) $u->roleValue())
            : strtoupper((string) ($u->level ?? ''));

        // kalau kamu punya enum role(), pakai juga, tapi tetap sinkron dengan list config
        $allowed = $this->kasiLevels(); // sudah uppercase

        $isKasi = in_array($val, $allowed, true);

        // optional: kalau punya enum dan mau lebih strict
        // $role = method_exists($u, 'role') ? $u->role() : null;
        // if ($role instanceof UserRole) $isKasi = in_array($role->value, $allowed, true);

        abort_unless($isKasi, 403);
    }

    public function approveForm(CaseResolutionTarget $target)
    {
        $this->ensureKasiLevel();

        // kalau kamu punya scope check, taruh di sini juga
        // abort_unless($org->isWithinKasiScope(...), 403);

        return view('kasi.approvals.targets.approve', compact('target'));
    }


    /**
     * Inbox logic untuk KASI:
     * - pending_kasi  (normal flow)
     * - pending_tl tapi needs_tl_approval = 0  (AO tanpa TL → langsung ke Kasi)
     */
    protected function applyInboxFilter($q): void
    {
        $q->where(function ($w) {
            $w->where('status', CaseResolutionTarget::STATUS_PENDING_KASI)
              ->orWhere(function ($x) {
                  $x->where('status', CaseResolutionTarget::STATUS_PENDING_TL)
                    ->where('needs_tl_approval', 0);
              });
        });
    }

    /**
     * Scope: KASI hanya boleh lihat bawahan / timnya.
     * Ini wajib biar aman (audit & kontrol akses).
     */
    protected function applyKasiScope($q, int $kasiId): void
    {
        // Cara paling stabil: filter via proposed_by (yang mengajukan target)
        // Dengan whereIn bawahan menurut OrgVisibilityService.
        // Kalau service kamu belum punya "list bawahan", kita pakai fallback whereHas
        // + filter manual di PHP (tapi itu berat). Jadi kita bikin query berbasis org_assignments.

        // ✅ REKOMENDASI: buat method di OrgVisibilityService:
        // subordinateUserIdsForKasi($kasiId): array<int>
        // Kalau sudah ada, pakai itu.

           $org = app(\App\Services\Org\OrgVisibilityService::class);

            $ids = method_exists($org, 'subordinateUserIdsForKasi')
                ? (array) $org->subordinateUserIdsForKasi($kasiId)
                : [];

            if (empty($ids)) {
                $q->whereRaw('1=0');
                return;
            }

            $q->whereIn('proposed_by', $ids);
        /**
         * Fallback minimal (kalau belum ada subordinateUserIdsForKasi):
         * Kita tidak bisa whereIn tanpa daftar.
         * Maka kita pakai gate per-row saat approve/reject + di listing kita biarkan tampil dulu.
         *
         * ⚠️ Tapi ini kurang aman karena listing bisa bocor (meski approve ditolak).
         * Jadi sebaiknya kamu tambah method subordinateUserIdsForKasi().
         */
        // nothing
    }

    public function index(Request $request)
    {
        $this->ensureKasiLevel();
        $kasiId = (int) auth()->id();

        /**
         * status UI:
         * - inbox (default) : pending_kasi + pending_tl(needs_tl_approval=0)
         * - pending_kasi
         * - pending_tl
         * - active / rejected / dll (opsional monitoring)
         */
        $statusUi = trim((string) $request->input('status', 'inbox'));

        $perPage  = max(5, (int) $request->input('per_page', 15));
        $kw       = trim((string) $request->input('q', ''));
        $overSla  = (int) $request->input('over_sla', 0) === 1;

        $q = CaseResolutionTarget::query()
            ->with(['nplCase.loanAccount', 'proposer']);

        // ✅ Scope KASI (recommended)
        $this->applyKasiScope($q, $kasiId);

        // ✅ filter status
        if ($statusUi === 'inbox') {
            $this->applyInboxFilter($q);
        } elseif ($statusUi !== '') {
            // status spesifik
            $q->where('status', $statusUi);
        }

        // ✅ search
        if ($kw !== '') {
            $q->where(function ($w) use ($kw) {
                $w->whereHas('nplCase.loanAccount', function ($x) use ($kw) {
                        $x->where('customer_name', 'like', "%{$kw}%");
                    })
                  ->orWhereHas('nplCase', function ($x) use ($kw) {
                        // kalau kolom ini ada
                        $x->where('debtor_name', 'like', "%{$kw}%");
                    })
                  ->orWhere('strategy', 'like', "%{$kw}%")
                  ->orWhere('target_outcome', 'like', "%{$kw}%");
            });
        }

        // ✅ over SLA (pakai created_at untuk SLA pending KASI)
        $kasiSlaDays = (int) config('crms.sla.kasi_days', 2);
        if ($overSla) {
            $q->where('created_at', '<', now()->subDays($kasiSlaDays));
        }

        // urutan rapi: yang paling lama pending dulu
        $rows = $q->orderBy('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $filters = [
            'status'   => $statusUi,
            'q'        => $kw,
            'per_page' => $perPage,
            'over_sla' => $overSla ? 1 : 0,
        ];

        return view('supervision.kasi.approvals.targets.index', compact('rows', 'filters', 'kasiSlaDays'));
    }

    public function approve(
        Request $request,
        CaseResolutionTarget $target,
        ResolutionTargetService $svc
    ) {
        // dd([
        //     'id' => auth()->id(),
        //     'raw_level' => auth()->user()->getRawOriginal('level'),
        //     'cast_level' => auth()->user()->level,
        //     'role' => auth()->user()->role()?->value,
        //     'roleValue' => auth()->user()->roleValue(),
        //     'hasKSR' => auth()->user()->hasAnyRole(['KSR']),
        //     ]);

        $this->ensureKasiLevel();

        $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // ✅ Gate per-row: target harus memang dalam scope Kasi
        $org = app(OrgVisibilityService::class);
        if (method_exists($org, 'isWithinKasiScope')) {
            abort_unless(
                $org->isWithinKasiScope((int) auth()->id(), (int) $target->proposed_by),
                403
            );
        }

        DB::transaction(function () use ($target, $svc, $request) {
            // lock target untuk hindari double-click / race
            $t = CaseResolutionTarget::query()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->firstOrFail();

            $st = strtolower((string) $t->status);

            // ✅ boleh approve kalau:
            // - pending_kasi
            // - pending_tl tapi needs_tl_approval=0 (langsung lompat)
            $ok = ($st === CaseResolutionTarget::STATUS_PENDING_KASI)
                || ($st === CaseResolutionTarget::STATUS_PENDING_TL && (int) $t->needs_tl_approval === 0);

            abort_unless($ok, 422);

            // Kalau masih pending_tl tapi skip TL → paksa jadi pending_kasi dulu (biar audit trail rapi)
            if ($st === CaseResolutionTarget::STATUS_PENDING_TL && (int) $t->needs_tl_approval === 0) {
                $t->status = CaseResolutionTarget::STATUS_PENDING_KASI;
                $t->save();
            }

            $svc->approveKasi($t, (int) auth()->id(), $request->input('notes'));
        });

        return back()->with('success', 'Target disetujui KASI → ACTIVE + auto-create agenda.');
    }

    public function reject(
        Request $request,
        CaseResolutionTarget $target,
        ResolutionTargetService $svc
    ) {
        $this->ensureKasiLevel();

        $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        // ✅ Gate per-row
        $org = app(OrgVisibilityService::class);
        if (method_exists($org, 'isWithinKasiScope')) {
            abort_unless(
                $org->isWithinKasiScope((int) auth()->id(), (int) $target->proposed_by),
                403
            );
        }

        DB::transaction(function () use ($target, $svc, $request) {
            $t = CaseResolutionTarget::query()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->firstOrFail();

            $st = strtolower((string) $t->status);

            $ok = ($st === CaseResolutionTarget::STATUS_PENDING_KASI)
                || ($st === CaseResolutionTarget::STATUS_PENDING_TL && (int) $t->needs_tl_approval === 0);

            abort_unless($ok, 422);

            // kalau pending_tl tapi skip TL → rapikan status dulu
            if ($st === CaseResolutionTarget::STATUS_PENDING_TL && (int) $t->needs_tl_approval === 0) {
                $t->status = CaseResolutionTarget::STATUS_PENDING_KASI;
                $t->save();
            }

            $svc->reject($t, (int) auth()->id(), $request->input('reason'));
        });

        return back()->with('success', 'Target ditolak KASI.');
    }
}
