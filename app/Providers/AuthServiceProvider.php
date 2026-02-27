<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use App\Models\AoAgenda;
use App\Policies\AoAgendaPolicy;
use Illuminate\Support\Facades\Gate;
use App\Models\OrgAssignment;
use App\Enums\UserRole;
use App\Models\User;
use App\Policies\KpiAoPolicy;
use App\Policies\KpiRoPolicy;
use App\Policies\KpiSoPolicy;
use App\Policies\KpiFePolicy;
use App\Policies\KpiBePolicy;


class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
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
        \App\Models\User::class => \App\Policies\KpiRoPolicy::class,
        \App\Models\User::class => \App\Policies\KpiSoPolicy::class,
        \App\Models\User::class => \App\Policies\KpiBePolicy::class,
        \App\Models\User::class => \App\Policies\KpiFePolicy::class,
        \App\Models\User::class => \App\Policies\KpiAoPolicy::class
    ];

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
        $this->registerPolicies();

        Gate::define('viewHtMonitoring', function ($user) {
        $level = strtolower(trim($user->roleValue()));
        
        // sesuaikan role yg boleh akses monitoring HT
        return in_array($level, ['kbl', 'kti', 'KSLU','KSLR','KSFE','KSBE', 'ksr', 'tlr','ao','so','fe','be','tll','ro','kom','dir', 'direksi'], true);
        });

        Gate::policy(OrgAssignment::class, OrgAssignmentPolicy::class);

        Gate::define('is-supervisor', function ($user) {
            return $user->inRoles(['DIREKSI','KABAG','KBL','KBO','KTI','KBF','KSR','KSLU','KSLR','KSFE','KSBE','KSO','KSA','KSF','KSD','TLR','TL','TLL','TLF','TLRO','TLSO','TLFE','TLBE','TLUM']);
        });

        Gate::define('manage-org-assignments', function ($user) {
            return $user->inRoles(['KABAG','KBL','KBO','KTI','KBF','DIREKSI']);
        });

        Gate::define('viewDashboard', function ($user) {
            // semua user login boleh
            return true;

            // atau kalau mau strict:
            // return $user->inRoles(['KTI','KBL','KBO','TL','KSLU','KSLR','KSFE','KSBE','KSR','DIREKSI','KOM']);
        });

        Gate::define('viewLegalMenu', function ($user) {
            return method_exists($user, 'canLegal') ? $user->canLegal() : false;
        });

        Gate::define('viewJobMonitor', function ($user) {
            return $user->hasAnyRole(['KTI','TI']); // sesuai keputusan kamu
        });

        Gate::define('viewLegalDashboard', function ($user) {
            $role = $user?->role(); // UserRole|null
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
            $role = $user->role(); // UserRole|null
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

        Gate::define('recalcMarketingKpi', function ($user) {
            return $user->hasAnyRole([
                'KSR', 'KTI', 'DIR', 'KSLU','KSLR','KSFE','KSBE', 'KOM','KBL',
            ]);
        });

        Gate::define('viewTlOsDashboard', function ($user) {
            $roleValue = method_exists($user, 'roleValue') ? strtoupper(trim((string)$user->roleValue())) : '';
            $level = strtoupper(trim((string)($user->level instanceof \BackedEnum ? $user->level->value : $user->level)));

            // TL family + management boleh
            return in_array($roleValue, ['TL','TLL','TLR','TLF','TLRO','TLSO','TLFE','TLBE','TLUM','KSLU','KSLR','KSFE','KSBE','KSM','KBL','KBO','KSA','DIR','PE','KOM'], true)
                || in_array($level, ['TL','SO','AO'], true); // kalau level TL dipakai
        });

        Gate::define('manageRoTargets', function ($user) {
            return $user?->hasAnyRole(['KBL']) === true;
        });

        Gate::define('kpi-ao-view', function (User $viewer, User $target) {
            return (new KpiAoPolicy())->view($viewer, $target);
        });

        Gate::define('kpi-ro-view', function (User $viewer, User $target) {
            return (new KpiRoPolicy())->view($viewer, $target);
        });

        Gate::define('kpi-so-view', function (User $viewer, User $target) {
            return (new KpiSoPolicy())->view($viewer, $target);
        });

        Gate::define('kpi-be-view', function (User $viewer, User $target) {
            return (new KpiBePolicy())->view($viewer, $target);
        });

        Gate::define('kpi-be-viewAny', [KpiBePolicy::class, 'viewAny']);

        Gate::define('kpi-fe-viewAny', function (User $viewer) {
            return (new KpiFePolicy())->viewAny($viewer);
        });

        Gate::define('kpi-fe-view', function (User $viewer, User $target) {
            return (new KpiFePolicy())->view($viewer, $target);
        });

        Gate::define('kpi-kslr-view', [\App\Policies\KpiKslrPolicy::class, 'view']);

        Gate::define('kpi-kbl-view', [\App\Policies\KpiKblPolicy::class, 'view']);

        Gate::define('kpi-ro-noa-manual-edit', function ($user) {
            $role = strtoupper((string)($user->roleValue() ?? ''));
            return in_array($role, ['TLRO', 'KSLR', 'KBL'], true);
        });

        Gate::define('kpi-ro-topup-adj-view', fn($u) => in_array($u->roleValue(), ['TLRO','KSLR','KBL'], true));
        Gate::define('kpi-ro-topup-adj-create', fn($u) => in_array($u->roleValue(), ['TLRO','KSLR'], true));
        Gate::define('kpi-ro-topup-adj-approve', fn($u) => $u->roleValue() === 'KBL'); // ✅ hanya KBL
    }
}
