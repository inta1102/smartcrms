@php
    use Illuminate\Support\Facades\Gate;
    use App\Models\ActionSchedule;
    use App\Models\NplCase;
    use App\Enums\UserRole;
    use App\Models\OrgAssignment;
    use App\Models\LegalActionProposal;


    // ========= helpers =========
    $is = fn($pattern) => request()->routeIs($pattern);

    $u = auth()->user();

    // ✅ Role single-source-of-truth (enum)
    $roleEnum  = $u ? $u->role() : null;                 // UserRole|null
    $roleValue = $u ? strtoupper($u->roleValue()) : '';  // string: "KSL", "TL", dst

    // ====== Groups (enum-safe) ======
    $isTl   = $roleEnum && in_array($roleEnum, [UserRole::TL, UserRole::TLL, UserRole::TLF, UserRole::TLR], true);
    $isKasi = $roleEnum && in_array($roleEnum, [UserRole::KSL, UserRole::KSO, UserRole::KSA, UserRole::KSF, UserRole::KSD, UserRole::KSR], true);
    $isKabagOrPe = $roleEnum && in_array($roleEnum, [UserRole::KABAG, UserRole::KBL, UserRole::KBO, UserRole::KTI, UserRole::KBF, UserRole::PE], true);
    $isPimpinan = $roleEnum && in_array($roleEnum, [
        UserRole::KABAG, UserRole::KBL, UserRole::KBO, UserRole::KTI, UserRole::KBF, UserRole::PE,
        UserRole::DIR, UserRole::DIREKSI, UserRole::KOM,
    ], true);

    // supervisor: TL ke atas (pakai enum function kamu)
    $isSupervisor = $roleEnum ? $roleEnum->isSupervisor() : false;

    // staff lapangan (yang punya agenda sendiri)
    $isFieldStaff = $roleEnum && in_array($roleEnum, [UserRole::AO, UserRole::SO, UserRole::FE, UserRole::BE], true);

    // org admin
    $isOrgAdmin = $roleEnum && in_array($roleEnum, [UserRole::KTI, UserRole::KABAG], true);

    // ====== Active: overdue vs cases.* (anti bentrok) ======
    $isOverdueActive = $is('cases.overdue');
    $isNplActive     = $is('cases.*') && !$isOverdueActive;

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

    // ====== Monitoring HT gate ======
    $canViewHtMonitoring = $u ? Gate::allows('viewHtMonitoring') : false;

    // ✅ 1 pintu supervisi: SELALU ke route supervision.home
    // biar user KASI gak pernah nyasar ke TL
    $supervisionHomeUrl = route('supervision.home');

    // ✅ URL Approval Target sesuai role
    $approvalTargetUrl = $supervisionHomeUrl;

    if ($isTl) {
        $approvalTargetUrl = route('supervision.tl.approvals.targets.index');
    } elseif ($isKasi) {
        $approvalTargetUrl = route('supervision.kasi.approvals.targets.index');
    }

    // ✅ URL Approval Non-Lit (TL & Kasi) - sesuai routes yang bener
    $approvalNonLitUrl = null;

    if ($isTl) {
        $approvalNonLitUrl = route('tl.approvals.nonlit.index');
    } elseif ($isKasi) {
        $approvalNonLitUrl = route('supervision.kasi.approvals.nonlit.index');
    }

    // ✅ active (TL + KASI)
    $isApprovalNonLitActive =
        request()->routeIs('tl.approvals.nonlit.*')
        || request()->routeIs('supervision.kasi.approvals.nonlit.*');


    // ✅ Active approvals (biar menu Approval Target nyala pas di halaman approvals)
    $isApprovalTargetsActive =
        $is('supervision.tl.approvals.targets.*')
        || $is('supervision.kasi.approvals.targets.*');

    
        // ✅ URL Approval Legal (TL & Kasi)
    $approvalLegalUrl = null;

    // TL melihat pending TL
    if ($isTl) {
        $approvalLegalUrl = route('legal.proposals.index', ['status' => 'pending_tl']);
    }
    // Kasi melihat yang sudah approved TL (butuh approve Kasi)
    elseif ($isKasi) {
        $approvalLegalUrl = route('legal.proposals.index', ['status' => 'approved_tl']);
    }

    $isApprovalLegalActive = $is('legal.proposals.*');

    // ✅ Active dashboard supervisi (biar Dashboard Supervisi nyala kalau bukan approvals)
    $isSupervisionDashboardActive =
        $is('supervision.home')
        || ($is('supervision.*') && !$isApprovalTargetsActive && !$isApprovalNonLitActive);

        // ====== Active states EWS ======
    $isEwsActive =
        $is('ews.*')
        || $is('rs.monitoring.*'); 

    $rsKritisRatio = $rsKritisRatio ?? 0;

    $isBE = strtoupper(trim($u?->roleValue() ?? '')) === 'BE';

    $routeIfExists = function (string $name, $params = []) {
        try {
            return \Illuminate\Support\Facades\Route::has($name)
                ? route($name, $params)
                : null;
        } catch (\Throwable $e) {
            return null;
        }
    };

    $legalHref = $routeIfExists('legal-actions.index');

    // ====== Helper: bawahan TL (aktif) ======
    $subordinateUserIds = function () use ($u, $isTl) {
        if (!$u || !$isTl) return [];

        try {
            $today = now()->toDateString();

            return OrgAssignment::query()
                ->where('leader_id', $u->id)
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

    // ====== Approval Legal badge (TL & Kasi) ======
    $legalApprovalBadge = null;

    try {
        if ($u && $isTl) {
            $ids = $subordinateUserIds();
            $legalApprovalBadge = empty($ids) ? 0 : LegalActionProposal::query()
                ->where('status', 'pending_tl')
                ->whereIn('proposed_by', $ids)
                ->count();
        }
        elseif ($u && $isKasi) {
            $legalApprovalBadge = LegalActionProposal::query()
                ->where('status', 'approved_tl') // atau pending_kasi
                ->count();
        }
    } catch (\Throwable $e) {
        $legalApprovalBadge = null;
    }

    $u = auth()->user();

    // ============================================
    // ✅ FAIL-SAFE: kalau composer belum inject, hitung di sini
    // ============================================
    $badgeApprovalTarget = $badgeApprovalTarget ?? null;
    $badgeApprovalTargetOverSla = $badgeApprovalTargetOverSla ?? null;

    if ($u && ($isTl || $isKasi)) {
        try {
            $svc = app(\App\Services\Crms\ApprovalBadgeService::class);

            if ($badgeApprovalTarget === null) {
                $badgeApprovalTarget = $isTl
                    ? $svc->tlTargetInboxCount((int)$u->id)
                    : $svc->kasiTargetInboxCount((int)$u->id);
            }

            // optional: kalau kamu sudah punya method overSLA
            if ($badgeApprovalTargetOverSla === null && method_exists($svc, 'kasiTargetOverSlaCount')) {
                $badgeApprovalTargetOverSla = $isTl
                    ? (method_exists($svc, 'tlTargetOverSlaCount') ? $svc->tlTargetOverSlaCount((int)$u->id) : 0)
                    : $svc->kasiTargetOverSlaCount((int)$u->id);
            }

        } catch (\Throwable $e) {
            $badgeApprovalTarget = $badgeApprovalTarget ?? 0;
            $badgeApprovalTargetOverSla = $badgeApprovalTargetOverSla ?? 0;
        }
    } else {
        $badgeApprovalTarget = $badgeApprovalTarget ?? 0;
        $badgeApprovalTargetOverSla = $badgeApprovalTargetOverSla ?? 0;
    }

    // default dashboard route
    $dashboardRouteName = 'dashboard';

    // KOM/DIR/DIREKSI → executive.targets.index
    if ($u && method_exists($u, 'hasAnyRole') && $u->hasAnyRole(['KOM', 'DIR', 'DIREKSI'])) {
        $dashboardRouteName = 'executive.targets.index';
    }

    // contoh kalau ada role lain
    elseif ($u && method_exists($u, 'hasAnyRole') && $u->hasAnyRole(['BE'])) {
        $dashboardRouteName = 'legal-actions.index';
    }

    $is = fn($name) => request()->routeIs($name . '*');

    $badge = (int)($badgeApprovalTarget ?? 0);
    $over  = (int)($badgeApprovalTargetOverSla ?? 0);

    // ===== Approval Non-Lit badge =====
    $badgeApprovalNonLit = 0;

    if ($u && ($isTl || $isKasi)) {
        try {
            $svcNonLit = app(\App\Services\Crms\NonLitApprovalBadgeService::class);

            $badgeApprovalNonLit = $isTl
                ? $svcNonLit->tlInboxCount((int) $u->id)
                : $svcNonLit->kasiInboxCount();

        } catch (\Throwable $e) {
            $badgeApprovalNonLit = 0;
        }
    }

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
            [
                'label'  => 'NPL Cases',
                'icon'   => '📁',
                'href'   => route('cases.index'),
                'active' => $isNplActive,
                'show'   => true,
            ],
            [
                'label'  => 'Overdue',
                'icon'   => '⏰',
                'href'   => route('cases.overdue'),
                'active' => $isOverdueActive,
                'badge'  => $overdueCount > 0 ? $overdueCount : null,
                'show'   => !$isPimpinan,
            ],
            [
                'label'  => 'Agenda Saya',
                'icon'   => '🗓️',
                'href'   => route('ao-agendas.my'),
                'active' => $isAgendaMine,
                'show'   => $isFieldStaff && !$isPimpinan,
                'badge'  => ($agendaBadge ?? 0) > 0 ? $agendaBadge : null,
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

                // ✅ badge auto
                'badge' => $badge > 0
                    ? ($over > 0 ? ($badge > 99 ? '99+' : $badge) . " ⚠️" . ($over > 99 ? '99+' : $over) : (string)min($badge,99))
                    : null,

                // optional: kalau kamu mau hide menu kalau bukan TL/KASI
                'show'   => !empty($approvalTargetUrl),
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
                'show'   => ($isTl || $isKasi) && !$isPimpinan,
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
        ] : [],

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
        'admin' => $isOrgAdmin ? [
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
            <div class="mt-1 text-[11px] text-slate-400">
                roleValue: {{ $u?->roleValue() }} | badgeTarget: {{ (int)($badgeApprovalTarget ?? 0) }}
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

{{-- Sidebar mobile drawer --}}
<div x-data="{ open:false }" x-on:toggle-sidebar.window="open = !open" x-cloak class="sm:hidden">
    <div x-show="open" class="fixed inset-0 z-40">
        <div class="absolute inset-0 bg-black/40" @click="open=false"></div>

        <aside class="absolute left-0 top-0 bottom-0 w-72 bg-white shadow-xl p-3">
            <div class="flex items-center justify-between px-2 py-2">
                <div>
                    <div class="text-sm font-bold text-slate-900">Menu</div>
                    <div class="text-xs text-slate-500">Navigasi utama</div>
                </div>
                <button class="rounded-lg border border-slate-200 px-2 py-1 text-sm" @click="open=false">✕</button>
            </div>

            <nav class="mt-2">
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

                {{-- LEGAL (opsional) --}}
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

                {{-- EWS (mobile konsisten: rs + macet) --}}
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

                                        @elseif($kind === 'macet')
                                            @php
                                                $warnRek = is_array($meta) ? (int)($meta['warn_rek'] ?? 0) : 0;
                                                $title = "Warning Usia Macet: {$warnRek} rek";
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

                {{-- ADMIN --}}
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
