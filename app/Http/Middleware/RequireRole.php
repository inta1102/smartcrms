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

        // roles bisa 1 string "TL,TLL" atau "TL|TLL"
        if (count($roles) === 1 && is_string($roles[0])) {
            $roles = preg_split('/[,\|]+/', $roles[0]) ?: $roles;
        }

        // normalize allowed roles
        $allowed = array_values(array_filter(array_map(
            fn($r) => strtoupper(trim((string) $r)),
            $roles
        )));

        // ✅ ambil role user secara paling stabil (sesuai pola project kamu)
        $val = null;

        if (method_exists($user, 'roleValue')) {
            $val = (string) $user->roleValue();     // biasanya "KSR"
        } elseif (property_exists($user, 'level')) {
            $val = (string) ($user->level ?? '');
        } elseif (property_exists($user, 'role')) {
            $val = (string) ($user->role ?? '');
        }

        $userRole = strtoupper(trim((string) $val));

        // 1) cek langsung via roleValue/level
        $ok = ($userRole !== '' && in_array($userRole, $allowed, true));

        // 2) fallback: kalau user punya hasAnyRole yang sudah teruji, pakai juga
        if (!$ok && method_exists($user, 'hasAnyRole')) {
            try {
                $ok = (bool) $user->hasAnyRole($allowed);
            } catch (\Throwable $e) {
                // abaikan, kita sudah punya cek langsung
            }
        }

        abort_unless($ok, 403);

        return $next($request);
    }
}
