<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\MarketingKpiTarget;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MarketingAchievementController extends Controller
{
    public function index(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        // filter period: format YYYY-MM-01
        $period = trim((string) $request->get('period', ''));

        $q = MarketingKpiTarget::query()
            ->where('user_id', $me->id)
            ->with(['achievement'])
            ->orderByDesc('period');

        if ($period !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $period)) {
            $q->whereDate('period', $period);
        } else {
            $period = '';
        }

        $rows = $q->paginate(12)->withQueryString();

        // dropdown period options (dari target milik user)
        $periodOptions = MarketingKpiTarget::query()
            ->where('user_id', $me->id)
            ->select('period')
            ->distinct()
            ->orderByDesc('period')
            ->pluck('period')
            ->map(fn($d) => Carbon::parse($d)->startOfMonth()->toDateString())
            ->unique()
            ->values()
            ->all();

        return view('kpi.marketing.achievements.index', compact(
            'rows','period','periodOptions'
        ));
    }
}
