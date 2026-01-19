<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        $userLevel = strtolower((string) ($user->level ?? ''));
        $allowed   = array_map(fn ($r) => strtolower(trim($r)), $roles);

        if (! in_array($userLevel, $allowed, true)) {
            abort(403);
        }

        return $next($request);
    }
}
