<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Cache;

// Models
use App\Models\OrgAssignment;
use App\Models\LoanAccount;
use App\Models\ShmCheckRequest;

// Enums
use App\Enums\UserRole;

// Observers
use App\Observers\HtAuctionObserver;
use App\Observers\HtUnderhandSaleObserver;
use App\Observers\HtDocumentObserver;

// Services
use App\Services\Restructure\RestructureDashboardService;
use App\Services\Ews\EwsMacetService;
use App\Services\Crms\ApprovalBadgeService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // =========================================================
        // Observers (fail-safe: only if model exists)
        // =========================================================
        if (class_exists(\App\Models\LegalActionHtAuction::class)) {
            \App\Models\LegalActionHtAuction::observe(HtAuctionObserver::class);
        }
        if (class_exists(\App\Models\LegalActionHtUnderhandSale::class)) {
            \App\Models\LegalActionHtUnderhandSale::observe(HtUnderhandSaleObserver::class);
        }
        if (class_exists(\App\Models\LegalActionHtDocument::class)) {
            \App\Models\LegalActionHtDocument::observe(HtDocumentObserver::class);
        }

        // =========================================================
        // Blade directive: @role('ao','tl',...)
        // =========================================================
        Blade::if('role', function (...$roles) {
            $user = auth()->user();
            if (!$user) return false;

            $level = strtolower((string) ($user->level ?? ''));
            $roles = array_map(fn ($r) => strtolower(trim((string)$r)), $roles);

            return in_array($level, $roles, true);
        });

        // =========================================================
        // Gate before: top management allow all
        // =========================================================
        Gate::before(function ($user, $ability) {
            if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole([
                UserRole::DIREKSI,
                UserRole::KABAG,
                UserRole::PE,
                UserRole::KTI,
            ])) {
                return true;
            }
            return null;
        });

        // =========================================================
        // ✅ SINGLE composer untuk sidebar (SATU PINTU)
        // =========================================================
        View::composer('layouts.partials.sidebar', function ($view) {
            $u = auth()->user();
            if (!$u) {
                $view->with('sidebarBadges', []);
                $view->with('rsKritisMeta', null);
                $view->with('rsKritisRatio', 0);
                $view->with('macetWarnMeta', null);
                return;
            }

            // -----------------------------
            // Role groups (string-safe)
            // -----------------------------
            $roleVal = method_exists($u, 'roleValue')
                ? strtoupper((string) $u->roleValue())
                : strtoupper((string) ($u->level ?? ''));

            $tlRoles   = ['TL','TLL','TLF','TLR'];
            $kasiRoles = ['KSL','KSO','KSA','KSF','KSD','KSR','KASI']; // tambah KASI kalau dipakai
            $sadRoles  = ['SAD','KSA','KBO']; // sesuai kebutuhan badge SHM

            $isTl   = in_array($roleVal, $tlRoles, true);
            $isKasi = in_array($roleVal, $kasiRoles, true);
            $isSad  = in_array($roleVal, $sadRoles, true);

            // -----------------------------
            // Base sidebar badges
            // -----------------------------
            $sidebarBadges = [
                'approval_target' => 0,
                'approval_target_over_sla' => 0,
                'shm' => 0,
            ];

            // =====================================================
            // 1) APPROVAL TARGET badges (TL/KASI) — scoped
            // =====================================================
            if ($isTl || $isKasi) {
                try {
                    $svc = app(ApprovalBadgeService::class);

                    if ($isTl) {
                        $sidebarBadges['approval_target'] = Cache::remember(
                            "badge:tl_target:{$u->id}",
                            now()->addSeconds(60),
                            fn () => (int) $svc->tlTargetInboxCount((int) $u->id)
                        );

                        if (method_exists($svc, 'tlTargetOverSlaCount')) {
                            $sidebarBadges['approval_target_over_sla'] = Cache::remember(
                                "badge:tl_target_over_sla:{$u->id}",
                                now()->addSeconds(60),
                                fn () => (int) $svc->tlTargetOverSlaCount((int) $u->id)
                            );
                        }
                    }

                    if ($isKasi) {
                        $sidebarBadges['approval_target'] = Cache::remember(
                            "badge:kasi_target:{$u->id}",
                            now()->addSeconds(60),
                            fn () => (int) $svc->kasiTargetInboxCount((int) $u->id)
                        );

                        if (method_exists($svc, 'kasiTargetOverSlaCount')) {
                            $sidebarBadges['approval_target_over_sla'] = Cache::remember(
                                "badge:kasi_target_over_sla:{$u->id}",
                                now()->addSeconds(60),
                                fn () => (int) $svc->kasiTargetOverSlaCount((int) $u->id)
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    // fail-safe: jangan bikin dashboard 500
                    $sidebarBadges['approval_target'] = 0;
                    $sidebarBadges['approval_target_over_sla'] = 0;
                }
            }

            // =====================================================
            // 2) SHM badge (SAD/KSA/KBO) — policy/scope aware
            // =====================================================
            if ($isSad) {
                try {
                    $sidebarBadges['shm'] = (int) ShmCheckRequest::query()
                        ->visibleFor($u) // pastikan scope ini ada di model
                        ->where('status', ShmCheckRequest::STATUS_SUBMITTED)
                        ->count();
                } catch (\Throwable $e) {
                    $sidebarBadges['shm'] = 0;
                }
            }

            // =====================================================
            // 3) RS Kritis + Macet Warn meta (cache + visibility)
            // =====================================================
            $latestDate = LoanAccount::max('position_date');

            $filter = [
                'position_date' => $latestDate,
                'branch_code'   => null,
                'ao_code'       => null,
            ];

            $visibleAoCodes = $this->visibleAoCodesForSidebar($u);

            // cache key stabil
            $aoKey = is_array($visibleAoCodes) ? md5(json_encode($visibleAoCodes)) : 'ALL';
            $rsCacheKey    = "sidebar:rs_kritis_meta:{$u->id}:{$latestDate}:{$aoKey}";
            $macetCacheKey = "sidebar:macet_warn_meta:{$u->id}:{$latestDate}:{$aoKey}";

            $rsMeta = null;
            $macetMeta = null;

            try {
                $rsSvc = app(RestructureDashboardService::class);
                $rsMeta = Cache::remember($rsCacheKey, now()->addMinutes(5), function () use ($rsSvc, $filter, $visibleAoCodes) {
                    return $rsSvc->kritisMeta($filter, $visibleAoCodes);
                });
            } catch (\Throwable $e) {
                $rsMeta = null;
            }

            try {
                $macetSvc = app(EwsMacetService::class);
                $macetMeta = Cache::remember($macetCacheKey, now()->addMinutes(5), function () use ($macetSvc, $filter, $visibleAoCodes) {
                    return $macetSvc->warnMeta($filter, $visibleAoCodes);
                });
            } catch (\Throwable $e) {
                $macetMeta = null;
            }

            // =====================================================
            // Output to view (single-source)
            // =====================================================
            $view->with('sidebarBadges', $sidebarBadges);

            $view->with('rsKritisMeta', $rsMeta);
            $view->with('rsKritisRatio', (float) ($rsMeta['ratio'] ?? 0));

            $view->with('macetWarnMeta', $macetMeta);
        });
    }

    /**
     * Visibility AO codes untuk sidebar (tanpa bergantung controller).
     * Return:
     * - null => ALL (top management)
     * - []   => empty visibility
     * - [..] => codes
     */
    private function visibleAoCodesForSidebar($u): ?array
    {
        // Top/Management: ALL
        if (method_exists($u, 'hasAnyRole') && $u->hasAnyRole([
            'DIREKSI','DIR','KOM','KABAG','KBL','KBO','KTI','KBF','PE'
        ])) {
            return null;
        }

        // Field staff: dirinya
        if (method_exists($u, 'hasAnyRole') && $u->hasAnyRole(['AO','BE','FE','SO','RO','SA'])) {
            $code = trim((string) ($u->employee_code ?? ''));
            if ($code === '') return [];
            return $this->normalizeAoCodesForSidebar([$code]);
        }

        // TL/KASI: bawahan langsung berdasar org_assignments
        if (method_exists($u, 'hasAnyRole') && $u->hasAnyRole([
            'TL','TLL','TLF','TLR',
            'KSL','KSO','KSA','KSF','KSD','KSR','KASI',
        ])) {
            $codes = OrgAssignment::query()
                ->active()
                ->where('leader_id', (int) $u->id)
                ->join('users', 'users.id', '=', 'org_assignments.user_id')
                ->whereNotNull('users.employee_code')
                ->pluck('users.employee_code')
                ->map(fn ($v) => trim((string)$v))
                ->filter()
                ->unique()
                ->values()
                ->all();

            return $this->normalizeAoCodesForSidebar($codes);
        }

        return [];
    }

    /**
     * Normalisasi AoCode untuk handle leading zero dan variasi format.
     */
    private function normalizeAoCodesForSidebar(array $codes): array
    {
        $out = [];
        foreach ($codes as $c) {
            $c = strtoupper(trim((string) $c));
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
