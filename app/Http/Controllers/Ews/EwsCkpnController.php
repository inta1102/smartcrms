<?php

namespace App\Http\Controllers\Ews;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Exports\EwsCkpnExport;
use Maatwebsite\Excel\Facades\Excel;
    

class EwsCkpnController extends Controller
{
    public function index(Request $request)
    {
        $latestPosDate = DB::table('loan_accounts')->max('position_date');
        if (!$latestPosDate) {
            return back()->with('error', 'Data loan_accounts belum tersedia.');
        }

        $posDate = $request->filled('position_date')
            ? Carbon::parse($request->get('position_date'))->toDateString()
            : Carbon::parse($latestPosDate)->toDateString();

        $minOs = (int) $request->get('min_os', 2500000000); // default 2.5M
        $dpdThreshold = (int) $request->get('dpd_gt', 7);   // default > 7

        $reason = $request->get('reason', 'all'); // all|rs|dpd|rs_dpd
        $q = trim((string) $request->get('q', ''));

        // ============================
        // A) Subquery: agregat per CIF
        // ============================
        $cifAgg = DB::table('loan_accounts')
            ->whereDate('position_date', $posDate)
            ->groupBy('cif')
            ->selectRaw('
                cif,
                SUM(outstanding) as os_cif,
                MAX(dpd) as dpd_max,
                MAX(is_restructured) as rs_any
            ');

        // ==========================================
        // B) Subquery: CIF eligible (rule CKPN)
        //    - OS per CIF >= 2.5M
        //    - RS = 1 OR dpd_max > 7
        // ==========================================
        $eligibleCifs = DB::query()
            ->fromSub($cifAgg, 'x')
            ->select('cif', 'os_cif', 'dpd_max', 'rs_any')
            ->where('os_cif', '>=', $minOs)
            ->where(function ($w) use ($dpdThreshold) {
                $w->where('rs_any', '=', 1)
                  ->orWhere('dpd_max', '>', $dpdThreshold);
            });

        // Optional filter reason (lebih “pinter”)
        if ($reason === 'rs') {
            $eligibleCifs->where('rs_any', 1)->where('dpd_max', '<=', $dpdThreshold);
        } elseif ($reason === 'dpd') {
            $eligibleCifs->where('rs_any', 0)->where('dpd_max', '>', $dpdThreshold);
        } elseif ($reason === 'rs_dpd') {
            $eligibleCifs->where('rs_any', 1)->where('dpd_max', '>', $dpdThreshold);
        }

        // ==========================================
        // C) Query output: per account_no (per norek)
        //    tapi join ke agregat CIF untuk reason & os_cif
        // ==========================================
        $rowsQ = DB::table('loan_accounts as la')
            ->joinSub($eligibleCifs, 'elig', function ($join) {
                $join->on('elig.cif', '=', 'la.cif');
            })
            ->whereDate('la.position_date', $posDate)
            ->select([
                'la.id',
                'la.position_date',
                'la.cif',
                'la.customer_name',
                'la.account_no',
                'la.product_type',
                'la.kolek',
                'la.dpd',
                'la.outstanding',
                'la.is_restructured',
                'elig.os_cif',
                'elig.dpd_max',
                'elig.rs_any',
            ])
            ->selectRaw("
                CASE
                    WHEN elig.rs_any=1 AND elig.dpd_max > ? THEN 'RS+DPD'
                    WHEN elig.rs_any=1 THEN 'RS'
                    WHEN elig.dpd_max > ? THEN 'DPD'
                    ELSE '-'
                END as reason
            ", [$dpdThreshold, $dpdThreshold])
            ->orderByDesc('elig.os_cif')
            ->orderByDesc('la.outstanding');

        // keyword search
        if ($q !== '') {
            $rowsQ->where(function ($w) use ($q) {
                $w->where('la.customer_name', 'like', "%{$q}%")
                  ->orWhere('la.cif', 'like', "%{$q}%")
                  ->orWhere('la.account_no', 'like', "%{$q}%");
            });
        }

        $rows = $rowsQ->paginate(25)->withQueryString();

        // ============================
        // D) Cards (per CIF)
        // ============================
        $cards = DB::query()
            ->fromSub($eligibleCifs, 'z')
            ->selectRaw('
                COUNT(*) as cif_count,
                SUM(os_cif) as os_total,
                SUM(CASE WHEN rs_any = 1 THEN 1 ELSE 0 END) as cif_rs,
                SUM(CASE WHEN dpd_max > ? THEN 1 ELSE 0 END) as cif_dpd,
                SUM(CASE WHEN rs_any = 1 AND dpd_max > ? THEN 1 ELSE 0 END) as cif_rs_dpd
            ', [$dpdThreshold, $dpdThreshold])
            ->first();

        // NOA per norek (jumlah row)
        $noa = (clone $rowsQ)->count();

        return view('ews.ckpn.index', compact(
            'posDate', 'minOs', 'dpdThreshold', 'reason', 'q',
            'rows', 'cards', 'noa'
        ));
    }

    public function export(Request $request)
    {
        $latestPosDate = DB::table('loan_accounts')->max('position_date');
        if (!$latestPosDate) {
            return back()->with('error', 'Data loan_accounts belum tersedia.');
        }

        $posDate = $request->filled('position_date')
            ? Carbon::parse($request->get('position_date'))->toDateString()
            : Carbon::parse($latestPosDate)->toDateString();

        $minOs = (int) $request->get('min_os', 2500000000);
        $dpdThreshold = (int) $request->get('dpd_gt', 7);
        $reason = (string) $request->get('reason', 'all'); // all|rs|dpd|rs_dpd
        $q = trim((string) $request->get('q', ''));

        // A) agregat per CIF
        $cifAgg = DB::table('loan_accounts')
            ->whereDate('position_date', $posDate)
            ->groupBy('cif')
            ->selectRaw('
                cif,
                SUM(outstanding) as os_cif,
                MAX(dpd) as dpd_max,
                MAX(is_restructured) as rs_any
            ');

        // B) eligible CIF
        $eligibleCifs = DB::query()
            ->fromSub($cifAgg, 'x')
            ->select('cif', 'os_cif', 'dpd_max', 'rs_any')
            ->where('os_cif', '>=', $minOs)
            ->where(function ($w) use ($dpdThreshold) {
                $w->where('rs_any', 1)
                ->orWhere('dpd_max', '>', $dpdThreshold);
            });

        if ($reason === 'rs') {
            $eligibleCifs->where('rs_any', 1)->where('dpd_max', '<=', $dpdThreshold);
        } elseif ($reason === 'dpd') {
            $eligibleCifs->where('rs_any', 0)->where('dpd_max', '>', $dpdThreshold);
        } elseif ($reason === 'rs_dpd') {
            $eligibleCifs->where('rs_any', 1)->where('dpd_max', '>', $dpdThreshold);
        }

        // C) output per rekening
        $rowsQ = DB::table('loan_accounts as la')
            ->joinSub($eligibleCifs, 'elig', function ($join) {
                $join->on('elig.cif', '=', 'la.cif');
            })
            ->whereDate('la.position_date', $posDate)
            ->select([
                'la.position_date',
                'la.cif',
                'la.customer_name',
                'la.account_no',
                'la.product_type',
                'la.dpd',
                'la.outstanding',
                'la.is_restructured',
                'elig.os_cif',
                'elig.dpd_max',
                'elig.rs_any',
            ])
            ->selectRaw("
                CASE
                    WHEN elig.rs_any=1 AND elig.dpd_max > ? THEN 'RS+DPD'
                    WHEN elig.rs_any=1 THEN 'RS'
                    WHEN elig.dpd_max > ? THEN 'DPD'
                    ELSE '-'
                END as reason
            ", [$dpdThreshold, $dpdThreshold])
            ->orderByDesc('elig.os_cif')
            ->orderByDesc('la.outstanding');

        if ($q !== '') {
            $rowsQ->where(function ($w) use ($q) {
                $w->where('la.customer_name', 'like', "%{$q}%")
                ->orWhere('la.cif', 'like', "%{$q}%")
                ->orWhere('la.account_no', 'like', "%{$q}%");
            });
        }

        $rows = $rowsQ->get();

        $file = sprintf(
            'EWS_CKPN_%s_minOS%s_dpdgt%s_%s.xlsx',
            $posDate,
            $minOs,
            $dpdThreshold,
            strtoupper($reason)
        );

        return Excel::download(new EwsCkpnExport($rows, $posDate, $minOs, $dpdThreshold, $reason, $q), $file);
    }

}
