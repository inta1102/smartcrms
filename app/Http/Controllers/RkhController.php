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
        // 1) kalau ada method role() (punyamu), pakai itu
        if (method_exists($user, 'role')) {
            $r = $user->role(); // ?UserRole
            if ($r instanceof UserRole) {
                // backed enum string: value = "TLRO", "RO", dst
                return strtoupper(trim((string)$r->value));
            }
            if (is_string($r) && $r !== '') {
                return strtoupper(trim($r));
            }
        }

        // 2) fallback: baca attribute level langsung (bisa enum / string)
        $lvl = $user->getAttribute('level');

        if ($lvl instanceof UserRole) {
            return strtoupper(trim((string)$lvl->value));
        }

        if (is_string($lvl) && trim($lvl) !== '') {
            return strtoupper(trim($lvl));
        }

        return 'RO';
    }

    public function index(Request $request)
    {
        $me = $request->user();
        abort_unless($me, 403);

        $role = $this->roleValue($me);

        // ===== scope user ids =====
        if ($role === 'TLRO') {
            $today = now()->toDateString();

            $scopeUserIds = OrgAssignment::query()
                ->where('leader_id', (int)$me->id)
                ->where('is_active', 1)
                ->where(function ($q) use ($today) {
                    $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $today);
                })
                ->pluck('user_id')
                ->map(fn($x) => (int)$x)
                ->unique()
                ->values()
                ->all();

            if (empty($scopeUserIds)) $scopeUserIds = [(int)$me->id];
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

        $status = $request->input('status'); // draft/submitted/approved/done
        $roId   = $request->input('ro_id');  // khusus TLRO

        // ===== query =====
        $rows = DB::table('rkh_headers as h')
            ->join('users as u', 'u.id', '=', 'h.user_id')
            ->leftJoin('rkh_details as d', 'd.rkh_id', '=', 'h.id')
            ->selectRaw("
                h.id,
                h.user_id,
                u.name as ro_name,
                h.tanggal,
                h.total_jam,
                h.status,
                COUNT(d.id) as total_items
            ")
            ->whereIn('h.user_id', $scopeUserIds)
            ->whereBetween('h.tanggal', [$from->toDateString(), $to->toDateString()])
            ->when($status, fn($q) => $q->where('h.status', $status))
            ->when(($role === 'TLRO' && $roId), fn($q) => $q->where('h.user_id', (int)$roId))
            ->groupBy('h.id','h.user_id','u.name','h.tanggal','h.total_jam','h.status')
            ->orderByDesc('h.tanggal')
            ->paginate(20)
            ->withQueryString();

        // list RO untuk filter dropdown (khusus TLRO)
        $ros = [];
        if ($role === 'TLRO') {
            $ros = DB::table('users')
                ->whereIn('id', $scopeUserIds)
                ->orderBy('name')
                ->get(['id','name']);
        }

        return view('rkh.index', [
            'rows' => $rows,
            'role' => $role,
            'ros'  => $ros,
            'from' => $from->toDateString(),
            'to'   => $to->toDateString(),
            'status' => $status,
            'roId'   => $roId,
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

        // validasi overlap + gap + jam kerja
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
            'details' => fn($q) => $q->orderBy('jam_mulai'),
            'details.lkh',
            'details.networking',
        ]);

        return view('rkh.show', compact('rkh'));
    }

    private function authorizeScope(RkhHeader $rkh): void
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $role = $this->roleValue($me);

        // owner always allowed
        if ((int)$rkh->user_id === (int)$me->id) return;

        if ($role === 'TLRO') {
            $today = Carbon::today()->toDateString();

            $subIds = OrgAssignment::query()
                ->where('leader_id', (int)$me->id)
                ->where('is_active', 1)
                ->where(function ($q) use ($today) {
                    $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $today);
                })
                ->pluck('user_id')
                ->map(fn($x) => (int)$x)
                ->unique()
                ->values()
                ->all();

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

        // ✅ account_no ikut di $items, tidak masuk ke config validator waktu
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

        // ✅ writer yang bertugas menyimpan semua field items termasuk account_no
        $writer->update($rkh, $items, $check['meta']);

        // kalau sebelumnya rejected, begitu RO edit, reset review TL
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

        // optional: pastikan ada detail
        $itemsCount = $rkh->details()->count();
        if ($itemsCount <= 0) {
            return back()->withErrors(['msg' => 'Tidak bisa submit. RKH belum memiliki item kegiatan.']);
        }

        $rkh->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            // reset approval/reject metadata
            'approved_by' => null,
            'approved_at' => null,
            'approval_note' => null,
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_note' => null,
        ]);

        // TODO: notif WA ke TLRO (opsional nanti)
        return back()->with('success', 'RKH berhasil disubmit ke TL.');
    }

    public function approve(Request $request, RkhHeader $rkh)
    {
        $this->authorizeScope($rkh);

        $me = $request->user();
        abort_unless($me, 403);

        $role = $this->roleValue($me);
        abort_unless($role === 'TLRO', 403);

        // hanya boleh approve jika submitted
        abort_unless($rkh->status === 'submitted', 403);

        $data = $request->validate([
            'approval_note' => ['nullable', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($rkh, $me, $data) {
            // 1) update header
            $rkh->status = 'approved';
            $rkh->approved_by = (int) $me->id;
            $rkh->approved_at = now();
            $rkh->approval_note = $data['approval_note'] ?? null;

            // bersihin reject fields
            $rkh->rejected_by = null;
            $rkh->rejected_at = null;
            $rkh->rejection_note = null;

            $rkh->save();

            // 2) set semua detail jadi approved (final)
            DB::table('rkh_details')
                ->where('rkh_id', (int) $rkh->id)
                ->update([
                    'tl_status'      => 'approved',
                    'tl_reviewed_by' => (int) $me->id,
                    'tl_reviewed_at' => now(),
                    // note per item jangan dihapus paksa (kalau sebelumnya ada), tapi biasanya aman dikosongkan
                    // 'tl_note' => null,
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
        abort_unless($role === 'TLRO', 403);

        // hanya boleh reject jika submitted
        abort_unless($rkh->status === 'submitted', 403);

        $data = $request->validate([
            'rejection_note' => ['nullable', 'string', 'max:5000'],
            'reject_ids'     => ['required', 'array', 'min:1'],
            'reject_ids.*'   => ['integer'],
            'tl_note'        => ['nullable', 'array'],
        ]);

        // validasi reject_ids memang milik rkh ini
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

            // 1) header jadi rejected
            $rkh->status = 'rejected';
            $rkh->rejected_by = (int) $me->id;
            $rkh->rejected_at = now();
            $rkh->rejection_note = $data['rejection_note'] ?? null;

            // bersihin approve fields
            $rkh->approved_by = null;
            $rkh->approved_at = null;
            $rkh->approval_note = null;

            $rkh->save();

            // 2) semua detail default approved (biar TL “merestui” yang OK)
            DB::table('rkh_details')
                ->where('rkh_id', (int)$rkh->id)
                ->update([
                    'tl_status'      => 'approved',
                    'tl_reviewed_by' => (int) $me->id,
                    'tl_reviewed_at' => now(),
                ]);

            // 3) detail yang dipilih jadi rejected + note per item
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
            ->with('success', 'RKH direject. RO diminta revisi item yang ditandai.');
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

        if ($role === 'RO') {
            return [(int)$me->id];
        }

        if ($role === 'TLRO') {
            $today = now()->toDateString();

            $ids = OrgAssignment::query()
                ->where('leader_id', (int)$me->id)
                ->where('is_active', 1)
                ->where(function ($q) use ($today) {
                    $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $today);
                })
                ->pluck('user_id')
                ->map(fn($x) => (int)$x)
                ->unique()
                ->values()
                ->all();

            return !empty($ids) ? $ids : [(int)$me->id];
        }

        return [(int)$me->id];
    }

    private function authorizeTlFor(RkhHeader $rkh): void
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $role = $this->roleValue($me);
        abort_if($role !== 'TLRO', 403);

        $today = now()->toDateString();

        $subIds = OrgAssignment::query()
            ->where('leader_id', (int)$me->id)
            ->where('is_active', 1)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')
                ->orWhere('effective_to', '>=', $today);
            })
            ->pluck('user_id')
            ->map(fn($x) => (int)$x)
            ->unique()
            ->values()
            ->all();

        abort_if(!in_array((int)$rkh->user_id, $subIds, true), 403);
    }

}
