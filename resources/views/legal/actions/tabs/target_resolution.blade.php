@php
    $case = $action->legalCase?->nplCase ?? null;
@endphp

<div class="space-y-4">

    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
        <div class="text-sm font-semibold text-slate-800">🎯 Target Penyelesaian (Monitoring Komisaris)</div>
        <div class="mt-1 text-xs text-slate-600">
            Target penyelesaian melekat pada <b>Kasus NPL</b> dan digunakan untuk supervisi TL/Kasi serta monitoring komisaris.
        </div>
    </div>

    @if($case)
        @include('npl_cases.tabs.target_resolution', ['case' => $case])
    @else
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
            NPL Case belum terhubung dari Legal Case.
            <div class="mt-1 text-xs text-rose-700/80">
                Cek relasi: <b>LegalAction → LegalCase → NplCase</b>.
                Pastikan <b>legal_cases.npl_case_id</b> (atau relasi alternatif) terisi.
            </div>
        </div>
    @endif

</div>
