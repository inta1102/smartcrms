<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class KpiTargetRouterController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $lvl = strtoupper(trim((string)($user->roleValue() ?? '')));
        if ($lvl === '') {
            $lvl = strtoupper(trim((string)(
                $user->level instanceof \BackedEnum ? $user->level->value : $user->level
            )));
        }

        return $lvl === 'SO'
            ? redirect()->route('kpi.so.targets.index')
            : redirect()->route('kpi.marketing.targets.index');
    }
}
