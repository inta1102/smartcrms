@php
    use Illuminate\Support\Facades\Gate;
    use App\Models\ActionSchedule;
    use App\Models\NplCase;
    use App\Enums\UserRole;
    use App\Models\OrgAssignment;
    use App\Models\LegalActionProposal;
    use App\Models\ShmCheckRequest;
    use Illuminate\Support\Facades\Route;

    // ========= helpers =========
    $is = fn(string $pattern) => request()->routeIs($pattern);

    $routeIfExists = function (string $name, $params = []) {
        try {
            return Route::has($name) ? route($name, $params) : null;
        } catch (\Throwable $e) {
            return null;
        }
    };

    $u = auth()->user();

    // ========= Role single-source-of-truth (enum) =========
    $roleEnum  = $u && method_exists($u, 'role') ? $u->role() : null;               // UserRole|null
    $roleValue = $u && method_exists($u, 'roleValue') ? strtoupper((string)$u->roleValue()) : ''; // "KSL", "TL", dst

    // ====== Groups (enum-first, fallback string) ======
    $isTl = ($roleEnum instanceof UserRole)
        ? in_array($roleEnum, [UserRole::TL, UserRole::TLL, UserRole::TLF, UserRole::TLR, UserRole::TLRO, UserRole::TLSO, UserRole::TLFE, UserRole::TLBE, UserRole::TLUM], true)
        : in_array($roleValue, ['TL','TLL','TLF','TLR','TLRO','TLSO','TLFE', 'TLBE', 'TLUM'], true);

    // KASI group (catatan: KTI bukan KASI, tapi kamu sebelumnya ikutkan. Aku pisahin biar rapi.)
    $isKasi = ($roleEnum instanceof UserRole)
        ? in_array($roleEnum, [UserRole::KSLU, UserRole::KSLR, UserRole::KSBE, UserRole::KSFE, UserRole::KSA, UserRole::KSF, UserRole::KSD, UserRole::KSR], true)
        : in_array($roleValue, ['KSLU','KSLR','KSFE','KSBE','KSA','KSF','KSD','KSR','KASI'], true);

    $isOrgAdmin = ($roleEnum instanceof UserRole)
        ? in_array($roleEnum, [UserRole::KTI, UserRole::KABAG], true)
        : in_array($roleValue, ['KTI','KABAG'], true);

    $isKabagOrPe = ($roleEnum instanceof UserRole)
        ? in_array($roleEnum, [UserRole::KABAG, UserRole::KBL, UserRole::KBO, UserRole::KTI, UserRole::KBF, UserRole::PE], true)
        : in_array($roleValue, ['KABAG','KBL','KBO','KTI','KBF','PE'], true);

    $isPimpinan = ($roleEnum instanceof UserRole)
        ? in_array($roleEnum, [
            UserRole::KABAG, UserRole::KBL, UserRole::KBO, UserRole::KTI, UserRole::KBF, UserRole::PE,
            UserRole::DIR, UserRole::DIREKSI, UserRole::KOM,
        ], true)
        : in_array($roleValue, ['KABAG','KBL','KBO','KTI','KBF','PE','DIR','DIREKSI','KOM'], true);

    // supervisor: TL ke atas (kalau enum punya function isSupervisor)
    $isSupervisor = ($roleEnum instanceof UserRole) ? $roleEnum->isSupervisor() : false;

    // staff lapangan (yang punya agenda sendiri)
    $isFieldStaff = ($roleEnum instanceof UserRole)
        ? in_array($roleEnum, [UserRole::AO, UserRole::RO, UserRole::SO, UserRole::FE, UserRole::BE], true)
        : in_array($roleValue, ['AO','RO','SO','FE','BE'], true);

    // ====== Active: overdue vs cases.* (anti bentrok) ======
    $isOverdueActive = $is('cases.overdue');
    $isNplActive     = $is('cases.*') && !$isOverdueActive;
    $isShmActive     = $is('shm.*');

    // ====== Active: KPI Communities ======
    $isKpiCommunityActive = $is('kpi.communities.*') || request()->is('kpi/communities*');


    // ====== Active states supervisi ======
    $isSupervisionActive =
        $is('supervision.*') ||
        $is('ao-agendas.ao') ||
        $is('ao-agendas.ao.pick');

    $isAgendaManual =
        $is('ao-agendas.index')
        || $is('ao-agendas.edit')
        || $is('ao-agendas.update')
        || $is('ao-agendas.start')
        || $is('ao-agendas.complete')
        || $is('ao-agendas.reschedule')
        || $is('ao-agendas.cancel');

    $isAgendaMine      = $is('ao-agendas.my');
    $isAgendaSupervisi = $is('ao-agendas.ao') || $is('ao-agendas.ao.pick');

    // ====== UI helpers ======
    $itemBase  = 'flex items-center gap-2 rounded-xl px-3 py-2 transition text-sm';
    $itemOn    = 'bg-slate-900 text-white shadow-sm';
    $itemOff   = 'text-slate-700 hover:bg-slate-100';
    $itemClass = fn(bool $active) => $itemBase.' '.($active ? $itemOn : $itemOff);

    $sectionTitle   = 'px-3 pt-3 text-[11px] font-bold uppercase tracking-wider text-slate-400';
    $sectionDivider = 'my-2 border-t border-slate-100';

    $badgeClass = function(bool $active) {
        return $active
            ? 'ml-auto inline-flex min-w-[22px] justify-center rounded-full bg-white/15 px-2 py-0.5 text-[11px] font-semibold text-white'
            : 'ml-auto inline-flex min-w-[22px] justify-center rounded-full bg-rose-600 px-2 py-0.5 text-[11px] font-semibold text-white';
    };

    $badgeWarnClass = function(bool $active) {
        return $active
            ? 'ml-auto inline-flex min-w-[22px] justify-center rounded-full bg-white/15 px-2 py-0.5 text-[11px] font-semibold text-white'
            : 'ml-auto inline-flex min-w-[22px] justify-center rounded-full bg-amber-500 px-2 py-0.5 text-[11px] font-semibold text-white';
    };

    // =========================================================
    // Badges dari composer (source-of-truth baru)
    // =========================================================
    // AppServiceProvider sekarang inject: $sidebarBadges = ['approval_target'=>..,'approval_target_over_sla'=>..,'shm'=>..]
    $sidebarBadges = $sidebarBadges ?? [];
    $badgeApprovalTarget        = (int) ($sidebarBadges['approval_target'] ?? 0);
    $badgeApprovalTargetOverSla = (int) ($sidebarBadges['approval_target_over_sla'] ?? 0);
    $shmBadge                   = (int) ($sidebarBadges['shm'] ?? 0);

    // =========================================================
    // FAIL-SAFE: kalau composer belum inject (misal partial dipakai di view lain)
    // =========================================================
    if ($u && ($isTl || $isKasi) && ($badgeApprovalTarget === 0 && empty($sidebarBadges))) {
        try {
            $svc = app(\App\Services\Crms\ApprovalBadgeService::class);

            $badgeApprovalTarget = $isTl
                ? (int) $svc->tlTargetInboxCount((int) $u->id)
                : (int) $svc->kasiTargetInboxCount((int) $u->id);

            if (method_exists($svc, 'kasiTargetOverSlaCount')) {
                $badgeApprovalTargetOverSla = $isTl
                    ? (method_exists($svc, 'tlTargetOverSlaCount') ? (int) $svc->tlTargetOverSlaCount((int) $u->id) : 0)
                    : (int) $svc->kasiTargetOverSlaCount((int) $u->id);
            }
        } catch (\Throwable $e) {
            $badgeApprovalTarget = 0;
            $badgeApprovalTargetOverSla = 0;
        }
    }

    // ====== Overdue badge count (fail-safe) ======
    $overdueCount = 0;
    if ($u) {
        try {
            $today = now()->toDateString();
            $overdueCount = NplCase::query()
                ->visibleFor($u)
                ->whereNull('closed_at')
                ->whereHas('latestDueAction', function ($q) use ($today) {
                    $q->whereDate('next_action_due', '<', $today);
                })
                ->count();
        } catch (\Throwable $e) {
            $overdueCount = 0;
        }
    }

    // ====== Agenda badge (fail-safe) ======
    $agendaBadge = null;
    if ($u && $isFieldStaff) {
        try {
            $agendaBadge = ActionSchedule::query()
                ->where('status', 'pending')
                ->where('scheduled_at', '<=', now()->endOfDay())
                ->whereHas('nplCase', fn($q) => $q->where('pic_user_id', $u->id))
                ->count();
        } catch (\Throwable $e) {
            $agendaBadge = null;
        }
    }

    // ====== Cek SHM gate ======
    $canViewShm = $u ? Gate::allows('viewAny', ShmCheckRequest::class) : false;

    // ====== SHM badge (fail-safe) - jika composer belum inject shm ======
    if ($u && $canViewShm && (empty($sidebarBadges) || !array_key_exists('shm', $sidebarBadges))) {
        try {
            $queueStatuses = [
                ShmCheckRequest::STATUS_SUBMITTED,
                ShmCheckRequest::STATUS_HANDED_TO_SAD,
            ];

            $q = ShmCheckRequest::query()->whereIn('status', $queueStatuses);

            if (method_exists(ShmCheckRequest::class, 'scopeVisibleFor')) {
                $q->visibleFor($u);
            }

            $shmBadge = (int) $q->count();
        } catch (\Throwable $e) {
            $shmBadge = 0;
        }
    }

    // ====== Monitoring HT gate ======
    $canViewHtMonitoring = $u ? Gate::allows('viewHtMonitoring') : false;

    // ✅ 1 pintu supervisi
    $supervisionHomeUrl = $routeIfExists('supervision.home') ?? '#';

    // ✅ URL Approval Target sesuai role
    $approvalTargetUrl = $supervisionHomeUrl;
    if ($isTl) {
        $approvalTargetUrl = $routeIfExists('supervision.tl.approvals.targets.index') ?? $supervisionHomeUrl;
    } elseif ($isKasi) {
        $approvalTargetUrl = $routeIfExists('supervision.kasi.approvals.targets.index') ?? $supervisionHomeUrl;
    }

    // ✅ URL Approval Non-Lit (TL & KASI)
    $approvalNonLitUrl = null;
    if ($isTl) {
        $approvalNonLitUrl = $routeIfExists('supervision.tl.approvals.nonlit.index');
    } elseif ($isKasi) {
        $approvalNonLitUrl = $routeIfExists('supervision.kasi.approvals.nonlit.index');
    }

    // ✅ Active approvals
    $isApprovalTargetsActive =
        $is('supervision.tl.approvals.targets.*') ||
        $is('supervision.kasi.approvals.targets.*');

    $isApprovalNonLitActive =
        $is('supervision.tl.approvals.nonlit.*') ||
        $is('supervision.kasi.approvals.nonlit.*');

    // ✅ URL Approval Legal (TL & KASI)
    $approvalLegalUrl = null;
    if ($isTl) {
        $approvalLegalUrl = $routeIfExists('legal.proposals.index', ['status' => 'pending_tl']);
    } elseif ($isKasi) {
        $approvalLegalUrl = $routeIfExists('legal.proposals.index', ['status' => 'approved_tl']);
    }
    $isApprovalLegalActive = $is('legal.proposals.*');

    // ✅ Active dashboard supervisi (biar Dashboard Supervisi nyala kalau bukan approvals)
    $isSupervisionDashboardActive =
        $is('supervision.home') ||
        ($is('supervision.*') && !$isApprovalTargetsActive && !$isApprovalNonLitActive);

    // ====== Active states EWS ======
    $isEwsActive = $is('ews.*') || $is('rs.monitoring.*');

    $rsKritisRatio = $rsKritisRatio ?? 0;

    // contoh flag role
    $isBE = $u ? (strtoupper(trim((string)($u->roleValue() ?? ''))) === 'BE') : false;

    // legal actions href
    $legalHref = $routeIfExists('legal-actions.index');

    // ====== Helper: bawahan TL (aktif) ======
    $subordinateUserIds = function () use ($u, $isTl) {
        if (!$u || !$isTl) return [];

        try {
            $today = now()->toDateString();

            return OrgAssignment::query()
                ->where('leader_id', (int)$u->id)
                ->where('is_active', 1)
                ->whereDate('effective_from', '<=', $today)
                ->where(function ($q) use ($today) {
                    $q->whereNull('effective_to')
                      ->orWhereDate('effective_to', '>=', $today);
                })
                ->pluck('user_id')
                ->map(fn($id) => (int) $id)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    };

    // ====== Approval Legal badge (TL & KASI) ======
    $legalApprovalBadge = 0;
    try {
        if ($u && $isTl) {
            $ids = $subordinateUserIds();
            $legalApprovalBadge = empty($ids) ? 0 : (int) LegalActionProposal::query()
                ->where('status', 'pending_tl')
                ->whereIn('proposed_by', $ids)
                ->count();
        } elseif ($u && $isKasi) {
            $legalApprovalBadge = (int) LegalActionProposal::query()
                ->where('status', 'approved_tl') // atau pending_kasi jika kamu pakai itu
                ->count();
        }
    } catch (\Throwable $e) {
        $legalApprovalBadge = 0;
    }

    // ====== Approval Non-Lit badge (fallback; idealnya dari composer juga) ======
    $badgeApprovalNonLit = 0;
    if ($u && ($isTl || $isKasi)) {
        try {
            $svc = app(\App\Services\Crms\ApprovalBadgeService::class);
            $badgeApprovalNonLit = $isTl
                ? (int) $svc->tlNonLitInboxCount((int) $u->id)
                : (int) $svc->kasiNonLitInboxCount((int) $u->id);
        } catch (\Throwable $e) {
            $badgeApprovalNonLit = 0;
        }
    }

    // default dashboard route
    $dashboardRouteName = 'lending.performance.index';
    if ($u && method_exists($u, 'hasAnyRole') && $u->hasAnyRole(['KOM', 'DIR', 'DIREKSI'])) {
        $dashboardRouteName = 'executive.targets.index';
    } elseif ($u && method_exists($u, 'hasAnyRole') && $u->hasAnyRole(['BE'])) {
        $dashboardRouteName = 'legal-actions.index';
    } elseif ($u && method_exists($u, 'hasAnyRole') && $u->hasAnyRole(['RO'])) {
        $dashboardRouteName = 'kpi.ro.os-daily';
    } elseif ($u && method_exists($u, 'hasAnyRole') && $u->hasAnyRole(['TLRO'])) {
        $dashboardRouteName = 'kpi.tl.os-daily';
    }

    $badge = (int) $badgeApprovalTarget;
    $over  = (int) $badgeApprovalTargetOverSla;

    // ====== Role checks utk RKH RO (enum-first, fallback string) ======
    $isRO = ($roleEnum instanceof UserRole)
        ? ($roleEnum === UserRole::RO)
        : ($roleValue === 'RO');

    $isTLRO = ($roleEnum instanceof UserRole)
        ? ($roleEnum === UserRole::TLRO)
        : ($roleValue === 'TLRO');

    $canRkh = ($u && ($isRO || $isTLRO));

    // active checker
    $isRkhActive = $is('rkh.*') || $is('lkh.*') || $is('lkh.recap.*');
    $isLkhActive = $is('lkh.*') || $is('lkh.recap.*');

    // ====== Active states KPI Communities ======
    $isKpiCommunityActive = $is('kpi.communities.*') || $is('kpi.communities'); 

    $canKpiThresholds = in_array($roleValue, ['KTI','KBL','PE','DIR','DIREKSI','KOM','KABAG'], true);


    $roleValue = $u ? strtoupper((string) $u->getRawOriginal('role')) : '';


    // ====== Menus ======
    $menus = [

        // ================= UTAMA =================
        'utama' => [
            [
                'label'  => 'Dashboard',
                'icon'   => '🏠',
                'href'   => route($dashboardRouteName),
                'active' => $is($dashboardRouteName),
                'show'   => true,
            ],

            // ✅ RKH RO (RO/TLRO)
            [
                'label'  => 'RKH RO',
                'icon'   => '📅',
                'href'   => route('rkh.index'),
                'active' => $isRkhActive,
                'show'   => $canRkh,
            ],
            
            // [
            //    'label'  => 'Kinerja RO',
            //    'icon'   => '📅',
            //    'href'   => route('kpi.tl.os-daily'),
            //    'active' => $isRkhActive,
            //    'show'   => $u && $u->hasAnyRole(['TLRO']),
            // ],
            //[
            //    'label'  => 'Kinerja RO',
            //    'icon'   => '📅',
            //    'href'   => route('kpi.ro.os-daily'),
            //    'active' => $isRkhActive,
            //    'show'   => $u && $u->hasAnyRole(['RO']),
            //],

            [
                'label'  => 'NPL Cases',
                'icon'   => '📁',
                'href'   => route('cases.index'),
                'active' => $isNplActive,
                'show'   => (!in_array($roleValue, ['KOM','RO'], true)) ,
            ],
            [
                'label'  => 'Overdue',
                'icon'   => '⏰',
                'href'   => route('cases.overdue'),
                'active' => $isOverdueActive,
                'badge'  => $overdueCount > 0 ? $overdueCount : null,
                'show'   => (!in_array($roleValue, ['KOM','RO'], true)) ,
            ],
            [
                'label'  => 'Agenda Saya',
                'icon'   => '🗓️',
                'href'   => route('ao-agendas.my'),
                'active' => $isAgendaMine,
                'show'   => (in_array($roleValue, ['BE','FE'], true)) && !$isPimpinan,
                'badge'  => ($agendaBadge ?? 0) > 0 ? $agendaBadge : null,
            ],
            
            [
                'label'  => 'Input Komunitas',
                'icon'   => '🏘️',
                'href'   => route('kpi.communities.index'),
                'active' => $isKpiCommunityActive,
                'show' => (in_array($roleValue, ['AO','SO','TLUM','KSLR'], true)) && !$isPimpinan,
            ],
        ],

        // ================= Aply =================
        'apply' => [
            
            
            [
                'label'  => 'Progess TopUp',
                'icon'   => '📅',
                // 'href'   => route('rkh.index'),
                'active' => $isRkhActive,
                'show'   => true,
            ],
            
            [
                'label'  => 'Cek SHM',
                'icon'   => '📄',
                'href' => route('shm.index'),
                'active' => $isShmActive,
                'show'   => $canViewShm,
                'badge'  => ($shmBadge ?? 0) > 0 ? $shmBadge : null,
                'badge_kind' => 'warn', // optional kalau kamu sudah bikin kind-based
            ],
            [
                'label'  => 'Order SP',
                'icon'   => '🏘️',
                // 'href'   => route('kpi.communities.index'),
                // 'active' => $isKpiCommunityActive,
                'show' => true,
            ],
        ],

        // ================= LEGAL (KHUSUS BE) =================
        'legal' => $isBE ? [
            [
                'label'  => 'Legal Proposals',
                'icon'   => '📑',
                'href'   => route('legal.proposals.index', ['status' => 'approved_kasi']),
                'active' => $is('legal.proposals.*'),
                'show'   => true,
            ],
            [
                'label'  => 'Legal Actions',
                'icon'   => '⚖️',
                'href'   => route('legal-actions.index'),
                'active' => $is('legal-actions.*'),
                'show'   => true,
            ],
        ] : [],


        // ================= SUPERVISI =================
        'supervisi' => ($isSupervisor || $isPimpinan) && !$isBE ? [
            [
                'label'  => 'Dashboard Supervisi',
                'icon'   => '🧭',
                'href'   => $supervisionHomeUrl,
                'active' => $isSupervisionDashboardActive,
                'show'   => true,
            ],
            [
                'label'  => 'Approval Target',
                'icon'   => '✅',
                'href'   => $approvalTargetUrl, // ✅ dinamis TL/KASI
                'active' => request()->routeIs(
                    'supervision.kasi.approvals.targets.*',
                    'supervision.tl.approvals.targets.*',
                ),

                // ✅ sembunyikan untuk PE
                'show'   => ($roleValue !== 'PE'),

                // ✅ badge auto
                'badge'  => $badge > 0
                    ? ($over > 0
                        ? (($badge > 99 ? '99+' : $badge) . " ⚠ " . ($over > 99 ? '99+' : $over))
                        : ($badge > 99 ? '99+' : $badge)
                    )
                    : null,
            ],

            [
                'label'  => 'Approval Non-Lit',
                'icon'   => '📝',
                'href'   => $approvalNonLitUrl,
                'active' => $isApprovalNonLitActive,
                'show'   => (bool) $approvalNonLitUrl && !$isPimpinan,
                'badge'  => $badgeApprovalNonLit > 0 ? $badgeApprovalNonLit : null,
            ],
            [
                'label'  => 'Approval Legal',
                'icon'   => '⚖️',
                'href'   => $approvalLegalUrl,
                'active' => $isApprovalLegalActive,
                'show'   => (bool) $approvalLegalUrl && !$isPimpinan,
                'badge'  => ($legalApprovalBadge ?? 0) > 0 ? $legalApprovalBadge : null,
            ],

            [
                'label'  => 'Agenda AO (Supervisi)',
                'icon'   => '📄',
                'href'   => route('ao-agendas.index'),
                'active' => $isAgendaManual,
                'show'   => true,
            ],
            [
                'label'  => 'Dashboard AO',
                'icon'   => '📊',
                'href'   => route('dashboard.ao.index'),
                'active' => $is('dashboard.ao.*'),
                'show'   => true,
            ],
            [
                'label'  => 'Import Excel',
                'icon'   => '⬆️',
                'href'   => route('loans.import.form'),
                'active' => $is('loans.import.*'),
                'show' => (($isTl || $isKasi) && !$isPimpinan) || $isOrgAdmin || $isKabagOrPe,
            ],
        ] : [],

        // ================= EWS =================
        'ews' => ($isSupervisor || $isPimpinan) && !$isBE ? [
            [
                'label'  => 'EWS Summary',
                'icon'   => '🚨',
                'href'   => route('ews.summary'),
                'active' => $is('ews.summary'),
                'show'   => true,
            ],
            [
                'label'  => 'Monitoring RS',
                'icon'   => '🧩',
                'href'   => route('rs.monitoring.index'),
                'active' => $is('rs.monitoring.*'),
                'show'   => true,
                'warn_meta' => $rsKritisMeta ?? null,
            ],
            [
                'label'  => 'Usia Macet',
                'icon'   => '⛔',
                'href'   => route('ews.macet'),
                'active' => $is('ews.macet.*'),
                'show'   => true,
                'warn_kind' => 'macet',
                'warn_meta' => $macetWarnMeta ?? null,
            ],
            [
                'label'  => 'CKPN Individu',
                'icon'   => '✅',
                'href'   => route('ews.ckpn.index'),
                'active' => $is('ews.ckpn.*'),
                'show'   => true,
                // 'warn_kind' => 'macet',
                // 'warn_meta' => $macetWarnMeta ?? null,
            ],
        ] : [],

        // ================= KPI MARKETING =================
        'kpi_marketing' => [

            // =========================
            // ACHIEVEMENT (Marketing KPI Targets)
            // =========================
            [
                'label'  => 'Achievement',
                'icon'   => '🏆',
                'href'   => \Illuminate\Support\Facades\Route::has('kpi.marketing.achievements.index')
                    ? route('kpi.marketing.achievements.index')
                    : null,
                'active' => request()->routeIs('kpi.marketing.achievements.*'),

                // tampil untuk AO family (bukan SO)
                'show'   => $u && $u->hasAnyRole(['AO','SO','RO','FE','BE']),
            ],

           
            // =========================
            // RANKING KPI
            // =========================
            [
                'label'  => 'Ranking KPI',
                'icon'   => '🏆',
                'href'   => \Illuminate\Support\Facades\Route::has('kpi.ranking.home')
                    ? route('kpi.ranking.home')
                    : null,
                'active' => request()->routeIs('kpi.ranking.*'),
                'show'   => $u && in_array(
                    strtoupper((string)($u->level instanceof \BackedEnum ? $u->level->value : $u->level)),
                    ['AO','BE','FE','RO','SO'],
                    true
                ),
            ],

            // =========================
            // RANKING KPI TL KASI KABAG
            // =========================
            [
                'label'  => 'Ranking KPI',
                'icon'   => '🏆',
                'href'   => \Illuminate\Support\Facades\Route::has('kpi.marketing.ranking.index')
                    ? route('kpi.marketing.ranking.index')
                    : null,
                'active' => request()->routeIs('kpi.marketing.ranking.*'),
                'show'   => $u && in_array(
                    strtoupper((string)($u->level instanceof \BackedEnum ? $u->level->value : (string)$u->level)),
                    ['TL','TLL','TLR','TLF','TLRO','TLSO','TLFE','TLBE','TLUM','KSLU','KSLR','KSFE','KSBE','KSO','KSA','KSF','KSD','KSR','KBL'],
                    true
                ),
            ],

             [
                'label'  => 'KPI TLRO',
                'icon'   => '📊',
                'href'   => \Illuminate\Support\Facades\Route::has('kpi.tlro.sheet') ? route('kpi.tlro.sheet') : null,
                'active' => request()->routeIs('kpi.tlro.sheet*'),
                'show' => $u && in_array($u->roleValue(), ['TLRO','KBL'], true),
                
            ],

            [
                'label'  => 'KPI TLFE',
                'icon'   => '📊',
                'href'   => \Illuminate\Support\Facades\Route::has('kpi.tlfe.sheet') ? route('kpi.tlfe.sheet') : null,
                'active' => request()->routeIs('kpi.tlfe.sheet*'),
                'show' => $u && in_array($u->roleValue(), ['TLFE','KBL'], true),
                
            ],

            [
                'label'  => 'KPI TLBE',
                'icon'   => '📊',
                'href'   => \Illuminate\Support\Facades\Route::has('kpi.tlbe.sheet') ? route('kpi.tlbe.sheet') : null,
                'active' => request()->routeIs('kpi.ksbe.sheet*'),
                'show' => $u && in_array($u->roleValue(), ['TLBE','KBL'], true),
                
            ],

            [
                'label'  => 'KPI KSFE',
                'icon'   => '📊',
                'href'   => \Illuminate\Support\Facades\Route::has('kpi.ksfe.sheet') ? route('kpi.ksfe.sheet') : null,
                'active' => request()->routeIs('kpi.ksfe.sheet*'),
                'show' => $u && in_array($u->roleValue(), ['KSFE','KBL'], true),
                
            ],

            [
                'label'  => 'KPI KSLR',
                'icon'   => '📊',
                'href'   => \Illuminate\Support\Facades\Route::has('kpi.kslr.sheet') ? route('kpi.kslr.sheet') : null,
                'active' => request()->routeIs('kpi.kslr.sheet*'),
                'show' => $u && in_array($u->roleValue(), ['KSLR','KBL'], true),
                
            ],

            [
                'label'  => 'KPI KSBE',
                'icon'   => '📊',
                'href'   => \Illuminate\Support\Facades\Route::has('kpi.marketing.sheet.ksbe')
                    ? route('kpi.marketing.sheet.ksbe')
                    : null,
                'active' => request()->routeIs('kpi.marketing.sheet.ksbe'),
                'show'   => $u && in_array(
                    strtoupper((string)($u->level instanceof \BackedEnum ? $u->level->value : (string)$u->level)),
                    ['KSBE','KBL'],
                    true
                ),
            ],

            [
                'label'  => 'KPI Kabag Lending',
                'icon'   => '📊',
                'href'   => \Illuminate\Support\Facades\Route::has('kpi.kbl.sheet') ? route('kpi.kbl.sheet') : null,
                'active' => request()->routeIs('kpi.kbl.sheet*'),
                'show' => $u && in_array($u->roleValue(), ['KBO','KBL'], true),
                
            ],


        ],


        // =========================
        // SHEET KPI LEADERSHIP (KSBE)
        // =========================
        [
            'label'  => 'Sheet KPI BE',
            'icon'   => '📄',
            'href'   => \Illuminate\Support\Facades\Route::has('kpi.marketing.sheet')
                ? route('kpi.marketing.sheet', ['role' => 'KSBE'])
                : null,
            'active' => request()->routeIs('kpi.marketing.sheet') && request('role') === 'KSBE',

            // tampil khusus KSBE (+ optional KBL kalau kamu mau global view)
            'show'   => $u && in_array(
                strtoupper((string)($u->level instanceof \BackedEnum ? $u->level->value : (string)$u->level)),
                ['KSBE','KBL'],
                true
            ),
        ],


        // ================= MONITORING =================
        'monitoring' => [
            $canViewHtMonitoring
                ? [
                    'label'  => 'Monitoring Lelang',
                    'icon'   => '🧾',
                    'href'   => route('monitoring.ht.index'),
                    'active' => $is('monitoring.ht.*'),
                ]
                : [
                    'label'        => 'Monitoring Lelang',
                    'icon'         => '🧾',
                    'href'         => null,
                    'active'       => false,
                    'disabledText' => 'Menu dibatasi role.',
                ],
        ],

        // ================= ADMIN ================= 
        'admin' => ($isOrgAdmin || $canKpiThresholds) ? [
            [
                'label'  => 'Org Assignment',
                'icon'   => '🧩',
                'href'   => route('supervision.org.assignments.index'),
                'active' => $is('supervision.org.assignments.*'),
            ],
            [
                'label'  => 'Job Monitor',
                'icon'   => '⚙️',
                'href'   => route('admin.jobs.index'),
                'active' => $is('admin.jobs.index.*'),
            ],
            [
                'label'  => 'Input Target (KTI)',
                'icon'   => '🎯',
                'href'   => route('kti.targets.index'),
                'active' => $is('kti.targets.index.*'),
            ],
            [
                'label'  => 'KPI Thresholds',
                'icon'   => '📏',
                'href'   => route('kpi.thresholds.index'),
                'active' => $is('kpi.thresholds.*'),
            ],
        ] : [],
    ];
@endphp

{{-- Sidebar desktop --}}
<aside class="hidden sm:block w-64 shrink-0">
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-4 border-b border-slate-100">
            <div class="text-sm font-bold text-slate-900">Menu</div>
            <div class="text-xs text-slate-500">Navigasi utama</div>
            {{-- <div class="mt-1 text-[11px] text-slate-400">role: "{{ $roleValue }}"</div> --}}
           <div class="mt-1 text-[11px] text-slate-400">
                roleValue: {{ $u?->roleValue() }} |
                <!-- isBE: {{ $isBE ? 'YES' : 'NO' }} -->
            </div>

        </div>

        <nav class="p-2">
            {{-- UTAMA --}}
            <div class="{{ $sectionTitle }}">Utama</div>
            <div class="mt-2 space-y-1">
                @foreach($menus['utama'] as $m)
                    @if(!array_key_exists('show', $m) || $m['show'])
                        @if(!empty($m['href']))
                            <a href="{{ $m['href'] }}"
                               class="{{ $itemClass((bool)$m['active']) }}">
                                <span class="opacity-90">{{ $m['icon'] }}</span>
                                <span class="font-semibold flex-1">{{ $m['label'] }}</span>

                                @if(!empty($m['badge']))
                                    <span class="{{ ($m['label'] === 'Cek SHM') ? $badgeWarnClass((bool)$m['active']) : $badgeClass((bool)$m['active']) }}">
                                        {{ $m['badge'] }}
                                    </span>
                                @endif

                            </a>
                        @else
                            <div class="px-3 py-2 text-xs text-slate-400">
                                {{ $m['disabledText'] ?? 'Menu dibatasi role.' }}
                            </div>
                        @endif
                    @endif
                @endforeach
            </div>

            {{-- LEGAL (opsional, tampil kalau ada) --}}
            @if(!empty($menus['legal']))
                <div class="{{ $sectionDivider }}"></div>
                <div class="{{ $sectionTitle }}">Legal</div>
                <div class="mt-2 space-y-1">
                    @foreach($menus['legal'] as $m)
                        @if(!array_key_exists('show', $m) || $m['show'])
                            @if(!empty($m['href']))
                                <a href="{{ $m['href'] }}"
                                   class="{{ $itemClass((bool)$m['active']) }}">
                                    <span class="opacity-90">{{ $m['icon'] }}</span>
                                    <span class="font-semibold flex-1">{{ $m['label'] }}</span>

                                    @if(!empty($m['badge']))
                                        <span class="{{ $badgeClass((bool)$m['active']) }}">
                                            {{ $m['badge'] }}
                                        </span>
                                    @endif
                                </a>
                            @else
                                <div class="px-3 py-2 text-xs text-slate-400">
                                    {{ $m['disabledText'] ?? 'Menu dibatasi role.' }}
                                </div>
                            @endif
                        @endif
                    @endforeach
                </div>
            @endif

            {{-- SUPERVISI --}}
            @if(!empty($menus['supervisi']))
                <div class="{{ $sectionDivider }}"></div>
                <div class="{{ $sectionTitle }}">Supervisi</div>
                <div class="mt-2 space-y-1">
                    @foreach($menus['supervisi'] as $m)
                        @if(!array_key_exists('show', $m) || $m['show'])
                            @if(!empty($m['href']))
                                <a href="{{ $m['href'] }}" class="{{ $itemClass((bool)$m['active']) }}">
                                    <span class="opacity-90">{{ $m['icon'] }}</span>
                                    <span class="font-semibold flex-1">{{ $m['label'] }}</span>

                                    @if(!empty($m['badge']))
                                        <span class="{{ $badgeClass((bool)$m['active']) }}">
                                            {{ $m['badge'] }}
                                        </span>
                                    @endif
                                </a>
                            @else
                                <div class="px-3 py-2 text-xs text-slate-400">
                                    {{ $m['disabledText'] ?? 'Menu dibatasi role.' }}
                                </div>
                            @endif
                        @endif
                    @endforeach
                </div>
            @endif

            {{-- EWS --}}
            @if(!empty($menus['ews']))
                <div class="{{ $sectionDivider }}"></div>
                <div class="{{ $sectionTitle }}">EWS</div>
                <div class="mt-2 space-y-1">
                    @foreach($menus['ews'] as $m)
                        @if(!array_key_exists('show', $m) || $m['show'])
                            @if(!empty($m['href']))
                                <a href="{{ $m['href'] }}"
                                   class="{{ $itemClass((bool)$m['active']) }}">
                                    <span class="opacity-90">{{ $m['icon'] }}</span>
                                    <span class="font-semibold flex-1">{{ $m['label'] }}</span>

                                    @php
                                        $kind = $m['warn_kind'] ?? 'rs';
                                        $meta = $m['warn_meta'] ?? null;
                                    @endphp

                                    {{-- Badge RS --}}
                                    @if($kind === 'rs')
                                        @php
                                            $ratio = 0.0;
                                            if (is_array($meta)) $ratio = (float)($meta['ratio'] ?? 0);
                                            elseif (is_numeric($meta)) $ratio = (float)$meta;

                                            $ratioText = rtrim(rtrim(number_format($ratio, 2), '0'), '.');
                                            $rekKritis = is_array($meta) ? (int)($meta['rek_kritis'] ?? 0) : 0;
                                            $osKritis  = is_array($meta) ? (float)($meta['os_kritis'] ?? 0) : 0.0;

                                            $title = "RS Kritis: ".number_format($rekKritis)." rek | OS: Rp "
                                                .number_format($osKritis,0,',','.')
                                                ." ({$ratioText}%)";
                                        @endphp

                                        @if($ratio >= 30 && $ratio <= 40)
                                            <span class="ml-auto inline-flex items-center rounded-full bg-amber-500 px-2 py-0.5 text-[10px] font-semibold text-white"
                                                  title="{{ $title }}">
                                                Waspada {{ $ratioText }}%
                                            </span>
                                        @elseif($ratio > 40)
                                            <span class="ml-auto inline-flex items-center rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-semibold text-white"
                                                  title="{{ $title }}">
                                                Kritis {{ $ratioText }}%
                                            </span>
                                        @endif

                                    {{-- Badge Macet --}}
                                    @elseif($kind === 'macet')
                                        @php
                                            $warnRek = is_array($meta) ? (int)($meta['warn_rek'] ?? 0) : 0;
                                            $ag6     = is_array($meta) ? (int)($meta['ag6']['rek'] ?? 0) : 0;
                                            $ag9     = is_array($meta) ? (int)($meta['ag9']['rek'] ?? 0) : 0;

                                            $warnOs  = is_array($meta) ? (float)($meta['warn_os'] ?? 0) : 0.0;
                                            $ratio   = is_array($meta) ? (float)($meta['ratio'] ?? 0) : 0.0;
                                            $ratioText = rtrim(rtrim(number_format($ratio, 2), '0'), '.');

                                            $title = "Warning Usia Macet: {$warnRek} rek | Ag6={$ag6}, Ag9={$ag9} | OS: Rp "
                                                .number_format($warnOs,0,',','.')
                                                ." ({$ratioText}%)";
                                        @endphp

                                        @if($warnRek > 0)
                                            <span class="ml-auto inline-flex items-center rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-semibold text-white"
                                                  title="{{ $title }}">
                                                {{ $warnRek }} ⚠
                                            </span>
                                        @endif
                                    @endif
                                </a>
                            @else
                                <div class="px-3 py-2 text-xs text-slate-400">
                                    {{ $m['disabledText'] ?? 'Menu dibatasi role.' }}
                                </div>
                            @endif
                        @endif
                    @endforeach
                </div>
            @endif
            {{-- KPI MARKETING --}}
            @if(!empty($menus['kpi_marketing']))
                @php
                    $anyShow = collect($menus['kpi_marketing'])->contains(function ($m) {
                        return (!array_key_exists('show', $m) || $m['show']);
                    });
                @endphp

                @if($anyShow)
                    <div class="{{ $sectionDivider }}"></div>
                    <div class="{{ $sectionTitle }}">KPI Marketing</div>
                    <div class="mt-2 space-y-1">
                        @foreach($menus['kpi_marketing'] as $m)
                            @if(!array_key_exists('show', $m) || $m['show'])
                                @if(!empty($m['href']))
                                    <a href="{{ $m['href'] }}" class="{{ $itemClass((bool)$m['active']) }}">
                                        <span class="opacity-90">{{ $m['icon'] }}</span>
                                        <span class="font-semibold flex-1">{{ $m['label'] }}</span>

                                        @if(!empty($m['badge']))
                                            <span class="{{ $badgeClass((bool)$m['active']) }}">
                                                {{ $m['badge'] }}
                                            </span>
                                        @endif
                                    </a>
                                @else
                                    <div class="px-3 py-2 text-xs text-slate-400">
                                        {{ $m['disabledText'] ?? 'Menu belum siap / route belum ada.' }}
                                    </div>
                                @endif
                            @endif
                        @endforeach
                    </div>
                @endif
            @endif
            
            {{-- Apply  --}}
            <div class="{{ $sectionDivider }}"></div>
            <div class="{{ $sectionTitle }}">Adminsitrasi</div>
            <div class="mt-2 space-y-1">
                @foreach($menus['apply'] as $m)
                    @if(!array_key_exists('show', $m) || $m['show'])
                        @if(!empty($m['href']))
                            <a href="{{ $m['href'] }}"
                               class="{{ $itemClass((bool)$m['active']) }}">
                                <span class="opacity-90">{{ $m['icon'] }}</span>
                                <span class="font-semibold flex-1">{{ $m['label'] }}</span>
                            </a>
                        @else
                            <div class="px-3 py-2 text-xs text-slate-400">
                                {{ $m['disabledText'] ?? 'TopUp / Order SP' }}
                            </div>
                        @endif
                    @endif
                @endforeach
            </div>

            {{-- MONITORING --}}
            <div class="{{ $sectionDivider }}"></div>
            <div class="{{ $sectionTitle }}">Monitoring</div>
            <div class="mt-2 space-y-1">
                @foreach($menus['monitoring'] as $m)
                    @if(!empty($m['href']))
                        <a href="{{ $m['href'] }}"
                           class="{{ $itemClass((bool)$m['active']) }}">
                            <span class="opacity-90">{{ $m['icon'] }}</span>
                            <span class="font-semibold">{{ $m['label'] }}</span>
                        </a>
                    @else
                        <div class="px-3 py-2 text-xs text-slate-400">
                            {{ $m['disabledText'] ?? 'Menu dibatasi role.' }}
                        </div>
                    @endif
                @endforeach
            </div>

            {{-- ADMINISTRASI --}}
            @can('manage-org-assignments')
                @if(!empty($menus['admin']))
                    <div class="{{ $sectionDivider }}"></div>
                    <div class="{{ $sectionTitle }}">Administrasi</div>
                    <div class="mt-2 space-y-1">
                        @foreach($menus['admin'] as $m)
                            <a href="{{ $m['href'] }}"
                               class="{{ $itemClass((bool)$m['active']) }}">
                                <span class="opacity-90">{{ $m['icon'] }}</span>
                                <span class="font-semibold">{{ $m['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            @endcan
        </nav>
    </div>
</aside>

{{-- Sidebar mobile drawer (MATCH DESKTOP) --}}
<div
    x-data="{ open:false }"
    x-on:toggle-sidebar.window="
        open = !open;
        if (open) {
            document.documentElement.classList.add('overflow-hidden');
        } else {
            document.documentElement.classList.remove('overflow-hidden');
        }
    "
    x-cloak
    class="sm:hidden"
>
    <div x-show="open" class="fixed inset-0 z-40">
        <div class="absolute inset-0 bg-black/40" @click="open=false"></div>

        <aside class="absolute left-0 top-0 h-dvh w-72 bg-white shadow-xl p-3 flex flex-col">
            <div class="flex items-center justify-between px-2 py-2">
                <div>
                    <div class="text-sm font-bold text-slate-900">Menu</div>
                    <div class="text-xs text-slate-500">Navigasi utama</div>

                    {{-- match desktop debug --}}
                    <div class="mt-1 text-[11px] text-slate-400">
                        roleValue: {{ $u?->roleValue() }} |
                        <!-- isBE: {{ $isBE ? 'YES' : 'NO' }} -->
                    </div>
                </div>

                <button class="rounded-lg border border-slate-200 px-2 py-1 text-sm" @click="open=false">✕</button>
            </div>

            <nav class="mt-2 flex-1 overflow-y-auto overscroll-contain">
                {{-- UTAMA --}}
                <div class="{{ $sectionTitle }}">Utama</div>
                <div class="mt-2 space-y-1">
                    @foreach($menus['utama'] as $m)
                        @if(!array_key_exists('show', $m) || $m['show'])
                            @if(!empty($m['href']))
                                <a href="{{ $m['href'] }}"
                                   class="{{ $itemClass((bool)$m['active']) }}">
                                    <span class="opacity-90">{{ $m['icon'] }}</span>
                                    <span class="font-semibold flex-1">{{ $m['label'] }}</span>

                                    @php
                                        $badgeCls = ($m['label'] === 'Cek SHM')
                                            ? $badgeWarnClass((bool)$m['active'])
                                            : $badgeClass((bool)$m['active']);
                                    @endphp

                                    @if(!empty($m['badge']))
                                        <span class="{{ $badgeCls }}">{{ $m['badge'] }}</span>
                                    @endif
                                </a>
                            @else
                                <div class="px-3 py-2 text-xs text-slate-400">
                                    {{ $m['disabledText'] ?? 'Menu dibatasi role.' }}
                                </div>
                            @endif
                        @endif
                    @endforeach
                </div>

                {{-- LEGAL (opsional, tampil kalau ada) --}}
                @if(!empty($menus['legal']))
                    <div class="{{ $sectionDivider }}"></div>
                    <div class="{{ $sectionTitle }}">Legal</div>
                    <div class="mt-2 space-y-1">
                        @foreach($menus['legal'] as $m)
                            @if(!array_key_exists('show', $m) || $m['show'])
                                @if(!empty($m['href']))
                                    <a href="{{ $m['href'] }}"
                                       class="{{ $itemClass((bool)$m['active']) }}">
                                        <span class="opacity-90">{{ $m['icon'] }}</span>
                                        <span class="font-semibold flex-1">{{ $m['label'] }}</span>

                                        @if(!empty($m['badge']))
                                            <span class="{{ $badgeClass((bool)$m['active']) }}">
                                                {{ $m['badge'] }}
                                            </span>
                                        @endif
                                    </a>
                                @else
                                    <div class="px-3 py-2 text-xs text-slate-400">
                                        {{ $m['disabledText'] ?? 'Menu dibatasi role.' }}
                                    </div>
                                @endif
                            @endif
                        @endforeach
                    </div>
                @endif

                {{-- SUPERVISI --}}
                @if(!empty($menus['supervisi']))
                    <div class="{{ $sectionDivider }}"></div>
                    <div class="{{ $sectionTitle }}">Supervisi</div>
                    <div class="mt-2 space-y-1">
                        @foreach($menus['supervisi'] as $m)
                            @if(!array_key_exists('show', $m) || $m['show'])
                                @if(!empty($m['href']))
                                    <a href="{{ $m['href'] }}" class="{{ $itemClass((bool)$m['active']) }}">
                                        <span class="opacity-90">{{ $m['icon'] }}</span>
                                        <span class="font-semibold flex-1">{{ $m['label'] }}</span>

                                        @if(!empty($m['badge']))
                                            <span class="{{ $badgeClass((bool)$m['active']) }}">
                                                {{ $m['badge'] }}
                                            </span>
                                        @endif
                                    </a>
                                @else
                                    <div class="px-3 py-2 text-xs text-slate-400">
                                        {{ $m['disabledText'] ?? 'Menu dibatasi role.' }}
                                    </div>
                                @endif
                            @endif
                        @endforeach
                    </div>
                @endif

                {{-- EWS (MATCH DESKTOP: rs + macet detail) --}}
                @if(!empty($menus['ews']))
                    <div class="{{ $sectionDivider }}"></div>
                    <div class="{{ $sectionTitle }}">EWS</div>
                    <div class="mt-2 space-y-1">
                        @foreach($menus['ews'] as $m)
                            @if(!array_key_exists('show', $m) || $m['show'])
                                @if(!empty($m['href']))
                                    <a href="{{ $m['href'] }}"
                                       class="{{ $itemClass((bool)$m['active']) }}">
                                        <span class="opacity-90">{{ $m['icon'] }}</span>
                                        <span class="font-semibold flex-1">{{ $m['label'] }}</span>

                                        @php
                                            $kind = $m['warn_kind'] ?? 'rs';
                                            $meta = $m['warn_meta'] ?? null;
                                        @endphp

                                        {{-- Badge RS --}}
                                        @if($kind === 'rs')
                                            @php
                                                $ratio = 0.0;
                                                if (is_array($meta)) $ratio = (float)($meta['ratio'] ?? 0);
                                                elseif (is_numeric($meta)) $ratio = (float)$meta;

                                                $ratioText = rtrim(rtrim(number_format($ratio, 2), '0'), '.');
                                                $rekKritis = is_array($meta) ? (int)($meta['rek_kritis'] ?? 0) : 0;
                                                $osKritis  = is_array($meta) ? (float)($meta['os_kritis'] ?? 0) : 0.0;

                                                $title = "RS Kritis: ".number_format($rekKritis)." rek | OS: Rp "
                                                    .number_format($osKritis,0,',','.')
                                                    ." ({$ratioText}%)";
                                            @endphp

                                            @if($ratio >= 30 && $ratio <= 40)
                                                <span class="ml-auto inline-flex items-center rounded-full bg-amber-500 px-2 py-0.5 text-[10px] font-semibold text-white"
                                                      title="{{ $title }}">
                                                    Waspada {{ $ratioText }}%
                                                </span>
                                            @elseif($ratio > 40)
                                                <span class="ml-auto inline-flex items-center rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-semibold text-white"
                                                      title="{{ $title }}">
                                                    Kritis {{ $ratioText }}%
                                                </span>
                                            @endif

                                        {{-- Badge Macet --}}
                                        @elseif($kind === 'macet')
                                            @php
                                                $warnRek = is_array($meta) ? (int)($meta['warn_rek'] ?? 0) : 0;
                                                $ag6     = is_array($meta) ? (int)($meta['ag6']['rek'] ?? 0) : 0;
                                                $ag9     = is_array($meta) ? (int)($meta['ag9']['rek'] ?? 0) : 0;

                                                $warnOs  = is_array($meta) ? (float)($meta['warn_os'] ?? 0) : 0.0;
                                                $ratio   = is_array($meta) ? (float)($meta['ratio'] ?? 0) : 0.0;
                                                $ratioText = rtrim(rtrim(number_format($ratio, 2), '0'), '.');

                                                $title = "Warning Usia Macet: {$warnRek} rek | Ag6={$ag6}, Ag9={$ag9} | OS: Rp "
                                                    .number_format($warnOs,0,',','.')
                                                    ." ({$ratioText}%)";
                                            @endphp

                                            @if($warnRek > 0)
                                                <span class="ml-auto inline-flex items-center rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-semibold text-white"
                                                      title="{{ $title }}">
                                                    {{ $warnRek }} ⚠
                                                </span>
                                            @endif
                                        @endif
                                    </a>
                                @else
                                    <div class="px-3 py-2 text-xs text-slate-400">
                                        {{ $m['disabledText'] ?? 'Menu dibatasi role.' }}
                                    </div>
                                @endif
                            @endif
                        @endforeach
                    </div>
                @endif

                {{-- KPI MARKETING --}}
                @if(!empty($menus['kpi_marketing']))
                    @php
                        $anyShow = collect($menus['kpi_marketing'])->contains(function ($m) {
                            return (!array_key_exists('show', $m) || $m['show']);
                        });
                    @endphp

                    @if($anyShow)
                        <div class="{{ $sectionDivider }}"></div>
                        <div class="{{ $sectionTitle }}">KPI Marketing</div>
                        <div class="mt-2 space-y-1">
                            @foreach($menus['kpi_marketing'] as $m)
                                @if(!array_key_exists('show', $m) || $m['show'])
                                    @if(!empty($m['href']))
                                        <a href="{{ $m['href'] }}" class="{{ $itemClass((bool)$m['active']) }}">
                                            <span class="opacity-90">{{ $m['icon'] }}</span>
                                            <span class="font-semibold flex-1">{{ $m['label'] }}</span>

                                            @if(!empty($m['badge']))
                                                <span class="{{ $badgeClass((bool)$m['active']) }}">
                                                    {{ $m['badge'] }}
                                                </span>
                                            @endif
                                        </a>
                                    @else
                                        <div class="px-3 py-2 text-xs text-slate-400">
                                            {{ $m['disabledText'] ?? 'Menu belum siap / route belum ada.' }}
                                        </div>
                                    @endif
                                @endif
                            @endforeach
                        </div>
                    @endif
                @endif

                {{-- Apply (MATCH DESKTOP) --}}
                <div class="{{ $sectionDivider }}"></div>
                <div class="{{ $sectionTitle }}">Adminsitrasi</div>
                <div class="mt-2 space-y-1">
                    @foreach($menus['apply'] as $m)
                        @if(!array_key_exists('show', $m) || $m['show'])
                            @if(!empty($m['href']))
                                <a href="{{ $m['href'] }}"
                                   class="{{ $itemClass((bool)$m['active']) }}">
                                    <span class="opacity-90">{{ $m['icon'] }}</span>
                                    <span class="font-semibold flex-1">{{ $m['label'] }}</span>
                                </a>
                            @else
                                <div class="px-3 py-2 text-xs text-slate-400">
                                    {{ $m['disabledText'] ?? 'TopUp / Order SP' }}
                                </div>
                            @endif
                        @endif
                    @endforeach
                </div>

                {{-- MONITORING --}}
                <div class="{{ $sectionDivider }}"></div>
                <div class="{{ $sectionTitle }}">Monitoring</div>
                <div class="mt-2 space-y-1">
                    @foreach($menus['monitoring'] as $m)
                        @if(!empty($m['href']))
                            <a href="{{ $m['href'] }}"
                               class="{{ $itemClass((bool)$m['active']) }}">
                                <span class="opacity-90">{{ $m['icon'] }}</span>
                                <span class="font-semibold">{{ $m['label'] }}</span>
                            </a>
                        @else
                            <div class="px-3 py-2 text-xs text-slate-400">
                                {{ $m['disabledText'] ?? 'Menu dibatasi role.' }}
                            </div>
                        @endif
                    @endforeach
                </div>

                {{-- ADMINISTRASI (MATCH DESKTOP, gate manage-org-assignments) --}}
                @can('manage-org-assignments')
                    @if(!empty($menus['admin']))
                        <div class="{{ $sectionDivider }}"></div>
                        <div class="{{ $sectionTitle }}">Administrasi</div>
                        <div class="mt-2 space-y-1">
                            @foreach($menus['admin'] as $m)
                                <a href="{{ $m['href'] }}"
                                   class="{{ $itemClass((bool)$m['active']) }}">
                                    <span class="opacity-90">{{ $m['icon'] }}</span>
                                    <span class="font-semibold">{{ $m['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endcan
            </nav>
        </aside>
    </div>
</div>