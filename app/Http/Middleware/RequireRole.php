<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use BackedEnum;

class RequireRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        
        $user = $request->user();
        abort_unless($user, 401);

        // roles bisa 1 string "TL,TLL" atau "TL|TLL"
        if (count($roles) === 1 && is_string($roles[0])) {
            $roles = preg_split('/[,\|]+/', $roles[0]) ?: $roles;
        }

        // normalize allowed roles
        $allowed = array_values(array_filter(array_map(
            fn($r) => strtoupper(trim((string) $r)),
            $roles
        )));

        // ✅ ambil role user secara stabil (Eloquent-safe)
        $val = null;

        // 1) roleValue() kalau ada
        if (method_exists($user, 'roleValue')) {
            $val = $user->roleValue(); // bisa string atau enum
        }

        // 2) fallback: attribute 'level' (Eloquent attribute)
        if ($val === null || $val === '') {
            $val = $user->getAttribute('level'); // ✅ ini yang benar
        }

        // 3) fallback: attribute 'role' (kalau ada legacy)
        // if ($val === null || $val === '') {
        //     $val = $user->getAttribute('role');
        // }

        // ✅ kalau enum, ambil value nya
        if ($val instanceof BackedEnum) {
            $val = $val->value;
        }

        $userRole = strtoupper(trim((string) $val));

        // 1) cek langsung
        $ok = ($userRole !== '' && in_array($userRole, $allowed, true));

        // 2) fallback: hasAnyRole kalau ada
        if (!$ok && method_exists($user, 'hasAnyRole')) {
            try {
                $ok = (bool) $user->hasAnyRole($allowed);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // \Log::info('RequireRole CHECK', [
        //     'path' => $request->path(),
        //     'allowed' => $allowed,
        //     'user_id' => $user->id ?? null,
        //     'user_level_attr' => $user->getAttribute('level'),
        //     'roleValue_exists' => method_exists($user,'roleValue'),
        //     'roleValue' => method_exists($user,'roleValue') ? $user->roleValue() : null,
        //     'resolved_userRole' => $userRole,
        //     'ok' => $ok,
        // ]);

        abort_unless($ok, 403);

        return $next($request);
    }
}