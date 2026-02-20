<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DisallowRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();
        abort_unless($user, 401);

        // normalize deny roles
        if (count($roles) === 1 && is_string($roles[0])) {
            $roles = preg_split('/[,\|;]+/', $roles[0]) ?: $roles;
        }

        $deny = array_values(array_filter(array_map(
            fn($r) => strtoupper(trim((string)$r)),
            $roles
        )));

        // ambil role stabil (pakai roleValue() yang sudah kamu punya)
        $userRole = method_exists($user, 'roleValue')
            ? strtoupper(trim((string)$user->roleValue()))
            : strtoupper(trim((string)($user->getAttribute('level')?->value ?? $user->getAttribute('level'))));

        abort_if(in_array($userRole, $deny, true), 403);

        return $next($request);
    }
}