<?php

namespace App\Http\Controllers;

use App\Models\RkhHeader;
use App\Services\Rkh\LkhRecapService;

class LkhRecapController extends Controller
{
    public function show(RkhHeader $rkh, LkhRecapService $svc)
    {
        // RO hanya boleh lihat rekap miliknya
        if ((int)$rkh->user_id !== (int)auth()->id()) abort(403);

        $data = $svc->build($rkh);

        // view siap cetak (TTD RO/TL/Kasi/Kabag)
        return view('lkh.recap', $data);
    }
}
