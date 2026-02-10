<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle($request, \Closure $next, ...$roles)
    {
        $u = $request->user();
        if (!$u) abort(401);

        $current = strtoupper(trim((string)($u->roleValue() ?? $u->level ?? '')));

        $expanded = [];

        foreach ($roles as $r) {
            $r = strtoupper(trim((string)$r));

            // ✅ token group
            if ($r === 'TL') {
                $expanded = array_merge(
                    $expanded,
                    array_map(fn($e) => $e->value, UserRole::tlAll())
                );
                continue;
            }

            $expanded[] = $r;
        }

        $expanded = array_values(array_unique($expanded));

        if (!in_array($current, $expanded, true)) {
            abort(403);
        }

        return $next($request);
    }
}
