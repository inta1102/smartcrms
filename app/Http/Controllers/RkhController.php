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

class RkhController extends Controller
{
    public function index()
    {
        $items = RkhHeader::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('tanggal')
            ->paginate(20);

        return view('rkh.index', compact('items'));
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
        $this->authorizeOwner($rkh);

        $rkh->load([
            'user',
            'approver',
            'details' => fn($q) => $q->orderBy('jam_mulai'),
            'details.lkh',
            'details.networking',
        ]);

        return view('rkh.show', compact('rkh'));
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

        return redirect()->route('rkh.show', $rkh->id)
            ->with('success', 'RKH diperbarui.');
    }

    public function submit(Request $request, RkhHeader $rkh, RkhWriter $writer)
    {
        $this->authorizeOwner($rkh);

        $writer->submit($rkh);

        return redirect()->route('rkh.show', $rkh->id)
            ->with('success', 'RKH berhasil disubmit ke TL.');
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
}
