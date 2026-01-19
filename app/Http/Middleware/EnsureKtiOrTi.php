<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureKtiOrTi
{
    public function handle(Request $request, Closure $next)
    {
        $u = auth()->user();
        if (!$u) abort(403);

        // ✅ Pakai helper role kamu kalau ada
        if (method_exists($u, 'hasAnyRole')) {
            if (!$u->hasAnyRole(['KTI', 'TI'])) abort(403);
            return $next($request);
        }

        // Kalau helper belum ada, fallback: cek field level/role (sesuaikan kalau punya)
        if (property_exists($u, 'role') && in_array($u->role, ['KTI', 'TI'], true)) {
            return $next($request);
        }

        abort(403);
    }
}
