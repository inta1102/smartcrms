@php
    $isEdit = ($mode ?? 'create') === 'edit';

    $oldUnit     = old('unit_code', $assignment?->unit_code ?? 'lending');
    $oldRole     = strtoupper(old('leader_role', $assignment?->leader_role ?? 'TL'));
    $oldUserId   = old('user_id', $assignment?->user_id);
    $oldLeaderId = old('leader_id', $assignment?->leader_id);
    $oldEffFrom  = old('effective_from', $assignment?->effective_from ?? now()->toDateString());
@endphp


<div class="grid grid-cols-1 gap-4 md:grid-cols-2">

    {{-- UNIT --}}
    <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Unit</label>
        <select name="unit_code" id="unit_code"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
            @foreach($units as $k => $label)
                <option value="{{ $k }}" @selected((string)$oldUnit === (string)$k)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">Menentukan aturan role atasan (pengawas vs bisnis/operasional).</p>
    </div>

    {{-- EFFECTIVE FROM --}}
    <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Effective From</label>
        <input type="date" name="effective_from" value="{{ $oldEffFrom }}"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
        <p class="mt-1 text-xs text-slate-500">Tanggal mulai assignment berlaku.</p>
    </div>

    {{-- STAFF --}}
    <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Staff / Bawahan</label>

        @if($isEdit)
            <div class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                {{ $assignment->staff?->name ?? '-' }}
                <span class="text-slate-500">({{ $assignment->staff?->level ?? '-' }})</span>
            </div>
            <input type="hidden" name="user_id" value="{{ $assignment->user_id }}">
            <p class="mt-1 text-xs text-slate-500">Staff dikunci. Untuk pindah staff, buat assignment baru.</p>
        @else
            <select name="user_id"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
                <option value="">-- pilih staff --</option>
                @foreach($staffUsers as $u)
                    <option value="{{ $u->id }}" @selected((string)$oldUserId === (string)$u->id)>
                        {{ $u->name }} ({{ $u->role_value ?: '-' }})
                    </option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">User yang ditempatkan sebagai bawahan pada unit ini.</p>
        @endif
    </div>


    {{-- ROLE ATASAN --}}
    <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Role Atasan (Struktural)</label>
        <select name="leader_role" id="leader_role"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
            @foreach($leaderRolesAll as $r)
                <option value="{{ $r }}" @selected($oldRole === $r)>{{ $r }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">
            Unit pengawas: PE/DIR. Unit lain: TL/KASI/KABAG (DIR opsional).
        </p>
    </div>

    {{-- ATASAN --}}
    <div class="md:col-span-2">
        <label class="block text-sm font-semibold text-slate-700 mb-1">Atasan Langsung</label>

        <select name="leader_id" id="leader_id"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none">
            <option value="">-- pilih atasan --</option>

            @foreach($leaderUsers as $u)
                <option value="{{ $u->id }}"
                        data-level="{{ strtoupper((string)($u->roleValue ?? '')) }}"
                        @selected((string)$oldLeaderId === (string)$u->id)>
                    {{ $u->name }} ({{ $u->roleValue ?? '-' }})
                </option>
            @endforeach
        </select>

        <p class="mt-1 text-xs text-slate-500">
            Dropdown ini difilter berdasarkan Role Atasan & mapping level user.
        </p>
    </div>
</div>

<script>
(function(){
    const oversightUnits = @json($oversightUnits);

    // Role struktural yang boleh dipilih berdasarkan unit
    const unitToAllowedRoles = (unit) => {
        unit = (unit || '').toLowerCase();
        if (oversightUnits.includes(unit)) return ['PE','DIREKSI'];
        return ['TL','KASI','KABAG','DIREKSI'];
    };

    // Mapping role struktural -> users.level yang dianggap “pejabat” tersebut
    // ✅ Sesuaikan dengan data kamu: TLL/KSL/KBL/KTI dst.
    // Jika di users.level kamu: TLL=TL, KSL=KASI, KBL=KABAG
    const roleToAllowedUserLevels = {
        'TL':   ['TLL','TLF','TLR'],
        'KASI': ['KSL','KSR', 'KSF', 'KSO', 'KSA', 'KSD'],
        'KABAG':['KBL','KBF', 'KBO', 'KTI'],
        'PE':   ['PE'],
        'DIR':  ['DIR','DIRUT','DIREKSI'],
    };

    const unitSel   = document.getElementById('unit_code');
    const roleSel   = document.getElementById('leader_role');
    const leaderSel = document.getElementById('leader_id');

    function filterRolesByUnit() {
        const unit = unitSel.value;
        const allowedRoles = unitToAllowedRoles(unit);

        // hide/show role options
        let roleValid = false;
        Array.from(roleSel.options).forEach(opt => {
            const r = (opt.value || '').toUpperCase();
            const show = allowedRoles.includes(r);
            opt.hidden = !show;
            if (opt.selected && show) roleValid = true;
            if (opt.selected && !show) opt.selected = false;
        });

        // kalau role jadi tidak valid, auto pilih pertama yang allowed
        if (!roleValid) {
            const firstAllowed = Array.from(roleSel.options).find(o => !o.hidden);
            if (firstAllowed) firstAllowed.selected = true;
        }
    }

    function filterLeadersByRole() {
        const role = (roleSel.value || '').toUpperCase();
        const allowedLevels = roleToAllowedUserLevels[role] || [];

        Array.from(leaderSel.options).forEach(opt => {
            if (!opt.value) return;
            const lv = (opt.dataset.level || '').toUpperCase();

            // kalau mapping kosong, jangan filter (fallback)
            const show = allowedLevels.length ? allowedLevels.includes(lv) : true;
            opt.hidden = !show;
            if (opt.selected && !show) opt.selected = false;
        });
    }

    function applyAll() {
        filterRolesByUnit();
        filterLeadersByRole();
    }

    unitSel.addEventListener('change', applyAll);
    roleSel.addEventListener('change', filterLeadersByRole);

    applyAll();
})();
</script>
