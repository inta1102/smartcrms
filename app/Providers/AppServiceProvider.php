<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use App\Models\OrgAssignment;
use App\Policies\OrgAssignmentPolicy;
use App\Enums\UserRole;
use App\Models\NplCase;

// === OBSERVERS ===
use App\Observers\HtAuctionObserver;
use App\Observers\HtUnderhandSaleObserver;
use App\Observers\HtDocumentObserver;

use App\Services\Restructure\RestructureDashboardService;
use App\Models\LoanAccount;
use Illuminate\Support\Facades\Cache;

use App\Services\Ews\EwsMacetService;

use Illuminate\Support\Facades\View;
use App\Services\Crms\ApprovalBadgeService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
        |--------------------------------------------------------------------------
        | HT AUCTION OBSERVER
        |--------------------------------------------------------------------------
        | Daftarkan observer HANYA jika modelnya benar-benar ada
        | Ini mencegah error "Class not found"
        */
        if (class_exists(\App\Models\LegalActionHtAuction::class)) {
            \App\Models\LegalActionHtAuction::observe(HtAuctionObserver::class);
        }

        /*
        |--------------------------------------------------------------------------
        | HT UNDERHAND SALE OBSERVER
        |--------------------------------------------------------------------------
        */
        if (class_exists(\App\Models\LegalActionHtUnderhandSale::class)) {
            \App\Models\LegalActionHtUnderhandSale::observe(HtUnderhandSaleObserver::class);
        }

        /*
        |--------------------------------------------------------------------------
        | HT DOCUMENT OBSERVER
        |--------------------------------------------------------------------------
        */
        if (class_exists(\App\Models\LegalActionHtDocument::class)) {
            \App\Models\LegalActionHtDocument::observe(HtDocumentObserver::class);
        }

        Blade::if('role', function (...$roles) {
            $user = auth()->user();
            if (! $user) return false;

            $level = strtolower((string) ($user->level ?? ''));
            $roles = array_map(fn($r) => strtolower(trim($r)), $roles);

            return in_array($level, $roles, true);
        });

        Gate::before(function ($user, $ability) {
            // super admin / top management
            if ($user->hasAnyRole([UserRole::DIREKSI, UserRole::KABAG, UserRole::PE, UserRole::KTI])) {
                return true;
            }
            return null;
        });

        view()->composer('layouts.partials.sidebar', function ($view) {
            if (!auth()->check()) return;

            $u = auth()->user();

            $svc = app(RestructureDashboardService::class);

            $latestDate = LoanAccount::max('position_date');

            $filter = [
                'position_date' => $latestDate,
                'branch_code'   => null,
                'ao_code'       => null,
            ];

            // ✅ visibility dibuat lokal (tidak bergantung controller)
            $visibleAoCodes = (function () use ($u) {
                // Top/Management: ALL
                if (method_exists($u, 'hasAnyRole') && $u->hasAnyRole(['DIREKSI','DIR','KOM','KABAG','KBL','KBO','KTI','KBF','PE'])) {
                    return null;
                }

                // Field staff: dirinya
                if (method_exists($u, 'hasAnyRole') && $u->hasAnyRole(['AO','BE','FE','SO','RO','SA'])) {
                    $code = $u->employee_code ? trim((string)$u->employee_code) : '';
                    if ($code === '') return [];
                    return $this->normalizeAoCodesForSidebar([$code]); // lihat helper di bawah
                }

                // TL/KASI: bawahan
                if (method_exists($u, 'hasAnyRole') && $u->hasAnyRole(['TL','TLL','TLF','TLR','KSL','KSO','KSA','KSF','KSD','KSR'])) {
                    if (!class_exists(OrgAssignment::class)) return [];

                    $codes = OrgAssignment::query()
                        ->where('leader_id', $u->id)
                        ->join('users', 'users.id', '=', 'org_assignments.user_id')
                        ->whereNotNull('users.employee_code')
                        ->pluck('users.employee_code')
                        ->map(fn($v) => trim((string)$v))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    return $this->normalizeAoCodesForSidebar($codes);
                }

                return [];
            })();

            // ✅ cache ringan biar sidebar gak “berat”
            $cacheKey = 'rs_kritis_meta:' . ($u->id ?? 0) . ':' . ($latestDate ?? 'null') . ':' . md5(json_encode($visibleAoCodes));

            $meta = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($svc, $filter, $visibleAoCodes) {
                return $svc->kritisMeta($filter, $visibleAoCodes);
            });

            $view->with('rsKritisMeta', $meta);
            $view->with('rsKritisRatio', (float)($meta['ratio'] ?? 0)); // kalau blade lama masih pakai ratio
        });

        // ...
        view()->composer('layouts.partials.sidebar', function ($view) {
            if (!auth()->check()) return;

            // =============================
            // RS Kritis meta (sudah ada)
            // =============================
            try {
                $svc = app(\App\Services\Rs\RestructureDashboardService::class);

                $latestDate = \App\Models\LoanAccount::max('position_date');

                $filter = [
                    'position_date' => $latestDate,
                    'branch_code'   => null,
                    'ao_code'       => null,
                ];

                $controller = app(\App\Http\Controllers\RestructureDashboardController::class);
                $visibleAoCodes = method_exists($controller, 'visibleAoCodes')
                    ? $controller->visibleAoCodes()
                    : null;

                $ratio = $svc->kritisRatio($filter, $visibleAoCodes);

                // kalau kamu sudah punya meta lebih lengkap:
                // $rsMeta = $svc->kritisMeta($filter, $visibleAoCodes);
                // $view->with('rsKritisMeta', $rsMeta);

                $view->with('rsKritisRatio', $ratio);
            } catch (\Throwable $e) {
                $view->with('rsKritisRatio', 0);
            }

            // =============================
            // NEW: Macet usia warning meta
            // =============================
            try {
                $latestDate = \App\Models\LoanAccount::max('position_date');

                $filter = [
                    'position_date' => $latestDate,
                    'branch_code'   => null,
                    'ao_code'       => null,
                ];

                $controller = app(\App\Http\Controllers\RestructureDashboardController::class);
                $visibleAoCodes = method_exists($controller, 'visibleAoCodes')
                    ? $controller->visibleAoCodes()
                    : null;

                $macetSvc  = app(EwsMacetService::class);
                $macetMeta = $macetSvc->warnMeta($filter, $visibleAoCodes);

                $view->with('macetWarnMeta', $macetMeta);
            } catch (\Throwable $e) {
                $view->with('macetWarnMeta', null);
            }
        });

        View::composer('*', function ($view) {
            $user = auth()->user();
            if (!$user) return;

            $roleVal = method_exists($user, 'roleValue')
                ? strtoupper((string) $user->roleValue())
                : strtoupper((string) ($user->level ?? ''));

            $isTl   = in_array($roleVal, ['TL','TLL','TLR','TLF'], true);
            $isKasi = in_array($roleVal, ['KSL','KSO','KSA','KSF','KSD','KSR'], true);

            // default
            $view->with('badgeApprovalTarget', 0);
            $view->with('approvalTargetUrl', null);

            if (!($isTl || $isKasi)) return;

            $svc = app(ApprovalBadgeService::class);

            if ($isTl) {
                $view->with('approvalTargetUrl', route('supervision.tl.approvals.targets.index'));

                $count = cache()->remember("badge:tl_target:{$user->id}", 60, fn () =>
                    $svc->tlTargetInboxCount((int) $user->id)
                );

                $view->with('badgeApprovalTarget', $count);
                return;
            }

            if ($isKasi) {
                $view->with('approvalTargetUrl', route('supervision.kasi.approvals.targets.index'));

                $count = cache()->remember("badge:kasi_target:{$user->id}", 60, fn () =>
                    $svc->kasiTargetInboxCount((int) $user->id)
                );

                $view->with('badgeApprovalTarget', $count);
                return;
            }
        });    
    }

    private function normalizeAoCodesForSidebar(array $codes): array
    {
        $out = [];
        foreach ($codes as $c) {
            $c = strtoupper(trim((string)$c));
            if ($c === '') continue;

            $out[] = $c;

            $noZero = ltrim($c, '0');
            if ($noZero !== '') $out[] = $noZero;

            if (ctype_digit($noZero)) {
                $out[] = str_pad($noZero, 6, '0', STR_PAD_LEFT);
            }
        }
        return array_values(array_unique(array_filter($out)));
    }
}
