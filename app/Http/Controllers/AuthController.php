<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class AuthController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'login'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $login    = trim((string) $data['login']);
        $password = (string) $data['password'];

        // normalisasi untuk pencarian case-insensitive
        $needle = mb_strtolower($login);

        $query = User::query();

        // email (case-insensitive)
        $query->whereRaw('LOWER(email) = ?', [$needle]);

        // username (case-insensitive) — hanya kalau kolomnya ada
        if (Schema::hasColumn('users', 'username')) {
            $query->orWhereRaw('LOWER(username) = ?', [$needle]);
        }

        // name (case sensitive default); kalau mau case-insensitive, pakai LOWER(name)
        $query->orWhere('name', $login);

        $user = $query->first();

        if ($user && Hash::check($password, $user->password)) {
            Auth::login($user);
            $request->session()->regenerate();

            $routeName = $this->landingRouteFor($user);

            // ✅ untuk BE & Executive, kita paksa landing page (abaikan intended)
            if (in_array($routeName, ['legal-actions.index', 'executive.targets.index','cases.index'], true)) {
                return redirect()->route($routeName);
            }

            // default: tetap hormati intended
            return redirect()->intended(route($routeName));
        }


        return back()->withErrors([
            'login' => 'Nama/email atau password salah.',
        ])->onlyInput('login');
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    protected function landingRouteFor(User $user): string
    {
        // 1) Executive dulu (paling “tinggi”)
        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['DIR', 'KOM'])) {
            return 'executive.targets.index';
        }

        // 2) BE khusus legal
        if (method_exists($user, 'hasRole') && $user->hasRole('BE')) {
            return 'legal-actions.index';
        }

        // 3) AO dan staff Remedial 
        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['AO', 'SO', 'SA', 'FE', 'RO'])) {
            return 'cases.index';
        }
        // default umum
        return 'dashboard';
    }

}
