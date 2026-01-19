<?php

namespace App\Http\Controllers\Supervision;

use App\Http\Controllers\Controller;
use App\Enums\UserRole;

class SupervisionHomeController extends Controller
{
    public function index()
    {
        $u = auth()->user();
        $role = $u?->role(); // UserRole|null

        if (!$role) abort(403);

        // =========================
        // TL
        // =========================
        if (in_array($role, [UserRole::TL, UserRole::TLL, UserRole::TLF, UserRole::TLR], true)) {
            return redirect()->route('supervision.tl');
        }

        // =========================
        // KASI
        // =========================
        if (in_array($role, [UserRole::KSL, UserRole::KSO, UserRole::KSA, UserRole::KSF, UserRole::KSD, UserRole::KSR], true)) {
            return redirect()->route('supervision.kasi');
        }

        // =========================
        // PIMPINAN (DIR/DIREKSI/KOM) -> sama dengan kabag
        // =========================
        if (in_array($role, [UserRole::DIR, UserRole::DIREKSI, UserRole::KOM], true)) {
            return redirect()->route('supervision.kabag');
        }

        // =========================
        // KABAG / PE
        // =========================
        if (in_array($role, [UserRole::KABAG, UserRole::KBL, UserRole::KBO, UserRole::KTI, UserRole::KBF, UserRole::PE], true)) {
            return redirect()->route('supervision.kabag');
        }

        abort(403);
    }
}
