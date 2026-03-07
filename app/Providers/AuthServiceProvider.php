<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

use App\Models\User;
use App\Models\AoAgenda;
use App\Models\OrgAssignment;

use App\Enums\UserRole;

use App\Policies\AoAgendaPolicy;
use App\Policies\OrgAssignmentPolicy;
use App\Policies\KpiAoPolicy;
use App\Policies\KpiRoPolicy;
use App\Policies\KpiSoPolicy;
use App\Policies\KpiFePolicy;
use App\Policies\KpiBePolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Policy mappings (STRICT MODEL POLICIES ONLY)
     */
    protected $policies = [

        \App\Models\LegalAction::class => \App\Policies\LegalActionPolicy::class,
        \App\Models\NplCase::class => \App\Policies\NplCasePolicy::class,
        \App\Models\LegalAdminChecklist::class => \App\Policies\LegalAdminChecklistPolicy::class,
        \App\Models\CaseResolutionTarget::class => \App\Policies\CaseResolutionTargetPolicy::class,
        \App\Models\AoAgenda::class => \App\Policies\AoAgendaPolicy::class,
        \App\Models\OrgAssignment::class => \App\Policies\OrgAssignmentPolicy::class,
        \App\Models\NonLitigationAction::class => \App\Policies\NonLitigationActionPolicy::class,
        \App\Models\LegalActionProposal::class => \App\Policies\LegalActionProposalPolicy::class,
        \App\Models\ShmCheckRequest::class => \App\Policies\ShmCheckRequestPolicy::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerPolicies();

        /*
        |--------------------------------------------------------------------------
        | GENERAL GATES
        |--------------------------------------------------------------------------
        */

        Gate::define('viewDashboard', fn($user) => true);

        Gate::define('is-supervisor', function ($user) {
            return $user->inRoles([
                'DIREKSI','KABAG','KBL','KBO','KTI','KBF',
                'KSR','KSLU','KSLR','KSFE','KSBE',
                'KSO','KSA','KSF','KSD',
                'TLR','TL','TLL','TLF','TLRO','TLSO','TLFE','TLBE','TLUM'
            ]);
        });

        Gate::define('manage-org-assignments', function ($user) {
            return $user->inRoles(['KABAG','KBL','KBO','KTI','KBF','DIREKSI']);
        });

        /*
        |--------------------------------------------------------------------------
        | LEGAL GATES
        |--------------------------------------------------------------------------
        */

        Gate::define('viewLegalMenu', fn($user) =>
            method_exists($user, 'canLegal') ? $user->canLegal() : false
        );

        Gate::define('viewLegalDashboard', function ($user) {
            $role = $user?->role();
            if (!$role) return false;

            return in_array($role, [
                UserRole::BE,
                UserRole::KBL,
                UserRole::KABAG,
                UserRole::DIR,
                UserRole::DIREKSI,
                UserRole::KOM,
            ], true);
        });

        Gate::define('manage-legal-actions', function (User $user) {
            $role = $user->role();
            if (!$role) return false;

            return in_array($role, [
                UserRole::BE,
                UserRole::KBL,
                UserRole::KABAG,
                UserRole::DIR,
                UserRole::DIREKSI,
                UserRole::KOM,
            ], true);
        });

        /*
        |--------------------------------------------------------------------------
        | KPI GATES (MODEL USER -> CUSTOM POLICY CALL)
        |--------------------------------------------------------------------------
        */

        Gate::define('kpi-ao-view', fn(User $viewer, User $target) =>
            (new KpiAoPolicy())->view($viewer, $target)
        );

        Gate::define('kpi-ro-view', fn(User $viewer, User $target) =>
            (new KpiRoPolicy())->view($viewer, $target)
        );

        Gate::define('kpi-so-view', fn(User $viewer, User $target) =>
            (new KpiSoPolicy())->view($viewer, $target)
        );

        Gate::define('kpi-be-view', fn(User $viewer, User $target) =>
            (new KpiBePolicy())->view($viewer, $target)
        );

        Gate::define('kpi-fe-view', fn(User $viewer, User $target) =>
            (new KpiFePolicy())->view($viewer, $target)
        );

        Gate::define('kpi-be-viewAny', [KpiBePolicy::class, 'viewAny']);
        Gate::define('kpi-fe-viewAny', [KpiFePolicy::class, 'viewAny']);

        Gate::define('kpi-kslr-view', [\App\Policies\KpiKslrPolicy::class, 'view']);
        Gate::define('kpi-kbl-view', [\App\Policies\KpiKblPolicy::class, 'view']);

        /*
        |--------------------------------------------------------------------------
        | KPI SPECIAL PERMISSION
        |--------------------------------------------------------------------------
        */

        Gate::define('kpi-ro-noa-manual-edit', fn($user) =>
            in_array($user->roleValue(), ['TLRO','KSLR','KBL'], true)
        );

        Gate::define('kpi-ro-topup-adj-view', fn($u) =>
            in_array($u->roleValue(), ['TLRO','KSLR','KBL'], true)
        );

        Gate::define('kpi-ro-topup-adj-create', fn($u) =>
            in_array($u->roleValue(), ['TLRO','KSLR'], true)
        );

        Gate::define('kpi-ro-topup-adj-approve', fn($u) =>
            $u->roleValue() === 'KBL'
        );

        Gate::define('recalcMarketingKpi', function ($user) {
            $rv = $user->roleValue();

            $role = $rv instanceof \BackedEnum
                ? strtoupper((string) $rv->value)
                : strtoupper((string) $rv);

            return in_array($role, ['KBL','ADMIN','SUPERADMIN'], true);
        });

        Gate::define('monitoring-ht-view', function ($user) {
            return in_array($user->roleValue(), [
                'KBL',
                'KSLR',
                'BE',
                'FE',
                
            ], true);
        });

        Gate::define('kpi-marketing-ranking-view', fn($user) => in_array($user->roleValue(), ['KBL','ADMIN','SUPERADMIN'], true));

        Gate::define('kpi-summary-view', function ($user) {
            $role = $user?->roleValue(); // "KBL", "KSLR", dst

            return in_array($role, [
                'KBL',
                'KSLR','KSFE','KSBE',
                'TLRO','TLFE','TLBE','TLUM','TLSO',
                // kalau mau super:
                'ADMIN','SUPERADMIN',
            ], true);
        });
    }
}