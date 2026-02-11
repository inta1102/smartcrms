<?php

namespace App\Http\Controllers;

use App\Http\Requests\Lkh\StoreLkhRequest;
use App\Http\Requests\Lkh\UpdateLkhRequest;
use App\Models\LkhReport;
use App\Models\RkhDetail;
use App\Services\Rkh\LkhWriter;

class LkhController extends Controller
{
    /**
     * Form buat isi LKH untuk 1 kegiatan RKH (detail).
     */
    public function create(RkhDetail $detail)
    {
        $this->authorizeDetailOwner($detail);

        // kalau sudah ada, redirect ke edit
        if ($detail->lkh) {
            return redirect()->route('lkh.edit', $detail->lkh);
        }

        return view('lkh.create', [
            'detail' => $detail->load('header','lkh','networking'),
        ]);
    }

    public function store(StoreLkhRequest $request, RkhDetail $detail, LkhWriter $writer)
    {
        $this->authorizeDetailOwner($detail);

        $payload = $request->validated();

        $report = $writer->store($detail, $payload);

        return redirect()->route('rkh.show', $detail->rkh_id)
            ->with('success', 'LKH tersimpan.');
    }

    public function edit(LkhReport $lkh)
    {
        $detail = $lkh->detail()->with('header','networking')->firstOrFail();
        $this->authorizeDetailOwner($detail);

        return view('lkh.edit', [
            'lkh' => $lkh->load('detail.header'),
            'detail' => $detail,
        ]);
    }

    public function update(UpdateLkhRequest $request, LkhReport $lkh, LkhWriter $writer)
    {
        $detail = $lkh->detail()->with('header')->firstOrFail();
        $this->authorizeDetailOwner($detail);

        $payload = $request->validated();

        $writer->update($lkh, $payload);

        return redirect()->route('rkh.show', $detail->rkh_id)
            ->with('success', 'LKH diperbarui.');
    }

    /**
     * Authorization sederhana: RO hanya boleh akses detail yg header.user_id = auth
     * (Kalau kamu sudah punya Policy/Gate, nanti kita pindah ke situ.)
     */
    private function authorizeDetailOwner(RkhDetail $detail): void
    {
        $detail->loadMissing('header');

        if ((int)$detail->header->user_id !== (int)auth()->id()) {
            abort(403);
        }
    }
}
