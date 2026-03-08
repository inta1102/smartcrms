<?php

namespace App\Http\Controllers;

use App\Models\RkhHeader;
use App\Services\Rkh\LkhRecapService;
use Barryvdh\DomPDF\Facade\Pdf;

class LkhRecapController extends Controller
{
    public function show(RkhHeader $rkh, LkhRecapService $svc)
    {
        if ((int)$rkh->user_id !== (int)auth()->id()) abort(403);

        $data = $svc->build($rkh);

        return view('lkh.recap', $data);
    }

    public function pdf(RkhHeader $rkh, LkhRecapService $svc)
    {
        if ((int)$rkh->user_id !== (int)auth()->id()) abort(403);

        $data = $svc->build($rkh);

        $filename = 'LKH-'.$rkh->user?->name.'-'.$rkh->tanggal->format('Y-m-d').'.pdf';

        $pdf = Pdf::loadView('lkh.recap_pdf', $data)
            ->setPaper('a4', 'portrait');

        return $pdf->download($filename);
        // kalau mau preview di tab browser:
        // return $pdf->stream($filename);
    }
}