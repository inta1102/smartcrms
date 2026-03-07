<?php

namespace App\Http\Controllers;

use App\Http\Requests\Rkh\StoreRkhRequest;
use App\Http\Requests\Rkh\UpdateRkhRequest;
use App\Models\MasterJenisKegiatan;
use App\Models\MasterTujuanKegiatan;
use App\Models\RkhHeader;
use App\Services\Rkh\RkhTimeValidator;
use App\Services\Rkh\RkhWriter;
use Illuminate\Http\Request;
use App\Services\Rkh\RkhSmartReminderService;
use App\Services\Rkh\RkhMasterService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\OrgAssignment;
use App\Enums\UserRole;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;

class RkhController extends Controller
{
    private function roleValue($user): string
    {
        if (method_exists($user, 'role')) {
            $r = $user->role();
            if ($r instanceof UserRole) {
                return strtoupper(trim((string)$r->value));
            }
            if (is_string($r) && $r !== '') {
                return strtoupper(trim($r));
            }
        }

        $lvl = $user->getAttribute('level');

        if ($lvl instanceof UserRole) {
            return strtoupper(trim((string)$lvl->value));
        }

        if (is_string($lvl) && trim($lvl) !== '') {
            return strtoupper(trim($lvl));
        }

        return 'RO';
    }

    /**
     * Role leader yang saat ini dipakai untuk RKH monitoring / approval langsung.
     * Fokus dulu ke yang sedang aktif di project: TLRO & TLFE.
     */
    private function isReviewerLeaderRole(string $role): bool
    {
        return in_array(strtoupper(trim($role)), [
            'TLRO',
            'TLFE',
        ], true);
    }

    /**
     * Mapping bawahan per role leader.
     * - TLRO monitor RO
     * - TLFE monitor FE
     */
    private function childRoleForLeader(string $leaderRole): ?string
    {
        $leaderRole = strtoupper(trim($leaderRole));

        return match ($leaderRole) {
            'TLRO' => 'RO',
            'TLFE' => 'FE',
            default => null,
        };
    }

    /**
     * Ambil subordinate aktif untuk leader, optional difilter sesuai role bawahan.
     */
    private function activeSubordinateUserIds($leader, ?string $childRole = null): array
    {
        $today = now()->toDateString();

        $q = OrgAssignment::query()
            ->from('org_assignments as oa')
            ->join('users as u', 'u.id', '=', 'oa.user_id')
            ->where('oa.leader_id', (int)$leader->id)
            ->where('oa.is_active', 1)
            ->whereDate('oa.effective_from', '<=', $today)
            ->where(function ($w) use ($today) {
                $w->whereNull('oa.effective_to')
                  ->orWhereDate('oa.effective_to', '>=', $today);
            });

        if ($childRole) {
            $q->whereRaw("UPPER(TRIM(COALESCE(u.level,''))) = ?", [strtoupper($childRole)]);
        }

        return $q->pluck('oa.user_id')
            ->map(fn ($x) => (int)$x)
            ->unique()
            ->values()
            ->all();
    }

    public function index(Request $request)
    {
        $me = $request->user();
        abort_unless($me, 403);

        $role = strtoupper((string)$this->roleValue($me));
        $isLeader = $this->isReviewerLeaderRole($role);
        $childRole = $this->childRoleForLeader($role);

        // ===== scope user ids =====
        if ($isLeader) {
            $scopeUserIds = $this->activeSubordinateUserIds($me, $childRole);

            // fallback aman kalau belum ada mapping aktif
            if (empty($scopeUserIds)) {
                $scopeUserIds = [(int)$me->id];
            }
        } else {
            $scopeUserIds = [(int)$me->id];
        }

        // ===== filters =====
        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : now()->subDays(14)->startOfDay();

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : now()->endOfDay();

        $status  = $request->input('status');
        $staffId = $request->input('staff_id');

        // ===== query =====
        $rows = DB::table('rkh_headers as h')
            ->join('users as u', 'u.id', '=', 'h.user_id')
            ->leftJoin('rkh_details as d', 'd.rkh_id', '=', 'h.id')
            ->selectRaw("
                h.id,
                h.user_id,
                u.name as staff_name,
                h.tanggal,
                h.total_jam,
                h.status,
                COUNT(d.id) as total_items
            ")
            ->whereIn('h.user_id', $scopeUserIds)
            ->whereBetween('h.tanggal', [$from->toDateString(), $to->toDateString()])
            ->when($status, fn ($q) => $q->where('h.status', $status))
            ->when(($isLeader && $staffId), fn ($q) => $q->where('h.user_id', (int)$staffId))
            ->groupBy('h.id', 'h.user_id', 'u.name', 'h.tanggal', 'h.total_jam', 'h.status')
            ->orderByDesc('h.tanggal')
            ->paginate(20)
            ->withQueryString();

        // ===== list staff untuk dropdown leader =====
        $staffs = [];
        if ($isLeader) {
            $staffs = DB::table('users')
                ->whereIn('id', $scopeUserIds)
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        return view('rkh.index', [
            'rows'     => $rows,
            'role'     => $role,
            'isLeader' => $isLeader,
            'staffs'   => $staffs,

            'from'     => $from->toDateString(),
            'to'       => $to->toDateString(),
            'status'   => $status,
            'staffId'  => $staffId,
        ]);
    }

    public function create(
        RkhMasterService $master,
        RkhSmartReminderService $reminderSvc
    ){
        $u = auth()->user();

        $jenis = $master->jenis();
        $tujuanByJenis = $master->tujuanByJenis();

        $smartReminder = [];

        try {
            $smartReminder = $reminderSvc->forViewer($u, null, 50);
        } catch (\Throwable $e) {
            Log::error('[SMART_REMINDER] forUser failed', [
                'user_id' => $u?->id,
                'msg' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 2000),
            ]);
            $smartReminder = [];
        }

        return view('rkh.create', compact('jenis','tujuanByJenis','smartReminder'));
    }

    public function store(StoreRkhRequest $request, RkhTimeValidator $tv, RkhWriter $writer)
    {
        $userId  = (int) auth()->id();
        $tanggal = (string) $request->input('tanggal');
        $items   = (array) $request->input('items', []);

        $check = $tv->validate($items, [
            'gap_tolerance_minutes' => 30,
            'max_gap_minutes' => 120,
            'require_no_overlap' => true,
            'work_start' => '08:00',
            'work_end' => '16:30',
        ]);

        if (!$check['ok']) {
            return back()->withInput()->withErrors(['items' => $check['errors']]);
        }

        $header = $writer->store($userId, $tanggal, $items, $check['meta']);

        return redirect()->route('rkh.show', $header->id)
            ->with('success', 'RKH tersimpan.');
    }

    public function show(RkhHeader $rkh)
    {
        $this->authorizeScope($rkh);

        $rkh->load([
            'user',
            'approver',
            'rejector',
            'details' => fn($q) => $q->orderBy('jam_mulai'),
            'details.lkh',
            'details.roVisit',
            'details.networking',
        ]);

        return view('rkh.show', compact('rkh'));
    }

    private function authorizeScope(RkhHeader $rkh): void
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $role = $this->roleValue($me);

        // owner selalu boleh
        if ((int)$rkh->user_id === (int)$me->id) {
            return;
        }

        // leader reviewer boleh akses bawahan aktif dalam scope-nya
        if ($this->isReviewerLeaderRole($role)) {
            $childRole = $this->childRoleForLeader($role);
            $subIds = $this->activeSubordinateUserIds($me, $childRole);

            abort_if(!in_array((int)$rkh->user_id, $subIds, true), 403);
            return;
        }

        abort(403);
    }

    public function edit(RkhHeader $rkh)
    {
        $this->authorizeOwner($rkh);

        if (!in_array($rkh->status, ['draft', 'rejected'], true)) {
            abort(403);
        }

        $rkh->load([
            'details' => fn($q) => $q->orderBy('jam_mulai'),
            'details.networking',
        ]);

        $jenis = MasterJenisKegiatan::query()
            ->where('is_active', 1)
            ->orderBy('sort')
            ->get(['code','label']);

        $tujuanByJenis = $this->buildTujuanMap();

        return view('rkh.edit', compact('rkh', 'jenis', 'tujuanByJenis'));
    }

    public function update(UpdateRkhRequest $request, RkhHeader $rkh, RkhTimeValidator $tv, RkhWriter $writer)
    {
        $this->authorizeOwner($rkh);

        $items = (array) $request->input('items', []);

        $check = $tv->validate($items, [
            'gap_tolerance_minutes' => 30,
            'max_gap_minutes' => 120,
            'require_no_overlap' => true,
            'work_start' => '08:00',
            'work_end' => '16:30',
        ]);

        if (!$check['ok']) {
            return back()->withInput()->withErrors(['items' => $check['errors']]);
        }

        $writer->update($rkh, $items, $check['meta']);

        if ($rkh->status === 'rejected') {
            $rkh->status = 'draft';
            $rkh->rejected_by = null;
            $rkh->rejected_at = null;
            $rkh->rejection_note = null;
            $rkh->save();

            DB::table('rkh_details')
                ->where('rkh_id', (int)$rkh->id)
                ->update([
                    'tl_status'      => 'pending',
                    'tl_note'        => null,
                    'tl_reviewed_by' => null,
                    'tl_reviewed_at' => null,
                ]);
        }

        return redirect()->route('rkh.show', $rkh->id)
            ->with('success', 'RKH diperbarui.');
    }

    public function submit(Request $request, RkhHeader $rkh)
    {
        $this->authorizeOwner($rkh);

        if (!in_array($rkh->status, ['draft', 'rejected'], true)) {
            abort(403);
        }

        $itemsCount = $rkh->details()->count();
        if ($itemsCount <= 0) {
            return back()->withErrors(['msg' => 'Tidak bisa submit. RKH belum memiliki item kegiatan.']);
        }

        $rkh->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'approved_by' => null,
            'approved_at' => null,
            'approval_note' => null,
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_note' => null,
        ]);

        return back()->with('success', 'RKH berhasil disubmit ke TL.');
    }

    public function approve(Request $request, RkhHeader $rkh)
    {
        $this->authorizeScope($rkh);

        $me = $request->user();
        abort_unless($me, 403);

        $role = $this->roleValue($me);
        abort_unless($this->isReviewerLeaderRole($role), 403);

        abort_unless($rkh->status === 'submitted', 403);

        $data = $request->validate([
            'approval_note' => ['nullable', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($rkh, $me, $data) {
            $rkh->status = 'approved';
            $rkh->approved_by = (int) $me->id;
            $rkh->approved_at = now();
            $rkh->approval_note = $data['approval_note'] ?? null;

            $rkh->rejected_by = null;
            $rkh->rejected_at = null;
            $rkh->rejection_note = null;

            $rkh->save();

            DB::table('rkh_details')
                ->where('rkh_id', (int) $rkh->id)
                ->update([
                    'tl_status'      => 'approved',
                    'tl_reviewed_by' => (int) $me->id,
                    'tl_reviewed_at' => now(),
                ]);
        });

        return redirect()
            ->route('rkh.show', $rkh->id)
            ->with('success', 'RKH di-approve.');
    }

    public function reject(Request $request, RkhHeader $rkh)
    {
        $this->authorizeScope($rkh);

        $me = $request->user();
        abort_unless($me, 403);

        $role = $this->roleValue($me);
        abort_unless($this->isReviewerLeaderRole($role), 403);

        abort_unless($rkh->status === 'submitted', 403);

        $data = $request->validate([
            'rejection_note' => ['nullable', 'string', 'max:5000'],
            'reject_ids'     => ['required', 'array', 'min:1'],
            'reject_ids.*'   => ['integer'],
            'tl_note'        => ['nullable', 'array'],
        ]);

        $validDetailIds = DB::table('rkh_details')
            ->where('rkh_id', (int)$rkh->id)
            ->pluck('id')
            ->map(fn($x) => (int)$x)
            ->all();

        $rejectIds = array_values(array_unique(array_map('intval', $data['reject_ids'])));
        foreach ($rejectIds as $did) {
            abort_unless(in_array($did, $validDetailIds, true), 403);
        }

        DB::transaction(function () use ($rkh, $me, $data, $rejectIds) {
            $rkh->status = 'rejected';
            $rkh->rejected_by = (int) $me->id;
            $rkh->rejected_at = now();
            $rkh->rejection_note = $data['rejection_note'] ?? null;

            $rkh->approved_by = null;
            $rkh->approved_at = null;
            $rkh->approval_note = null;

            $rkh->save();

            DB::table('rkh_details')
                ->where('rkh_id', (int)$rkh->id)
                ->update([
                    'tl_status'      => 'approved',
                    'tl_reviewed_by' => (int) $me->id,
                    'tl_reviewed_at' => now(),
                ]);

            foreach ($rejectIds as $did) {
                $note = null;
                if (!empty($data['tl_note']) && array_key_exists((string)$did, $data['tl_note'])) {
                    $note = trim((string)$data['tl_note'][(string)$did]);
                    if ($note === '') $note = null;
                }

                DB::table('rkh_details')
                    ->where('id', (int)$did)
                    ->update([
                        'tl_status' => 'rejected',
                        'tl_note'   => $note,
                    ]);
            }
        });

        return redirect()
            ->route('rkh.show', $rkh->id)
            ->with('success', 'RKH direject. Staff diminta revisi item yang ditandai.');
    }

    // ================= helpers =================

    private function authorizeOwner(RkhHeader $rkh): void
    {
        if ((int) $rkh->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }

    private function buildTujuanMap(): array
    {
        $tujuan = MasterTujuanKegiatan::query()
            ->where('is_active', 1)
            ->orderBy('jenis_code')
            ->orderBy('sort')
            ->get(['jenis_code','code','label']);

        $map = [];
        foreach ($tujuan as $t) {
            $map[$t->jenis_code][] = [
                'code'  => $t->code,
                'label' => $t->label,
            ];
        }
        return $map;
    }

    private function scopeUserIds($me): array
    {
        $role = $this->roleValue($me);

        if ($this->isReviewerLeaderRole($role)) {
            $childRole = $this->childRoleForLeader($role);
            $ids = $this->activeSubordinateUserIds($me, $childRole);

            return !empty($ids) ? $ids : [(int)$me->id];
        }

        return [(int)$me->id];
    }

    private function authorizeTlFor(RkhHeader $rkh): void
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $role = $this->roleValue($me);
        abort_if(!$this->isReviewerLeaderRole($role), 403);

        $childRole = $this->childRoleForLeader($role);
        $subIds = $this->activeSubordinateUserIds($me, $childRole);

        abort_if(!in_array((int)$rkh->user_id, $subIds, true), 403);
    }
}