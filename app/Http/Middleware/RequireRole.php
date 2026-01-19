<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();
        if (!$user) abort(401);

        // Robust parse: kalau roles dikirim sebagai 1 string "TL,TLL" atau "TL|TLL"
        if (count($roles) === 1 && is_string($roles[0])) {
            $roles = preg_split('/[,\|]+/', $roles[0]) ?: $roles;
        }

        // rapikan casing + spasi
        $roles = array_values(array_filter(array_map(fn($r) => strtoupper(trim((string)$r)), $roles)));

        if (!$user->hasAnyRole($roles)) {
            abort(403);
        }

        return $next($request);
    }
}
