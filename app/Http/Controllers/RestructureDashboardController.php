<?php

namespace App\Http\Controllers;

use App\Services\Restructure\RestructureDashboardService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\LoanAccount;


class RestructureDashboardController extends Controller
{
    public function __construct(
        protected RestructureDashboardService $svc
    ) {}

    public function index(Request $request)
    {
        // ✅ samakan default dengan EWS: latest snapshot
        $latestDate = LoanAccount::max('position_date');

        $filter = [
            'position_date' => $request->input('position_date', $latestDate ?: now()->toDateString()),
            'branch_code'   => $request->input('branch_code'),
            'ao_code'       => $request->input('ao_code'),
            'scope'         => $request->input('scope'),
        ];

        $visibleAoCodes = $this->visibleAoCodes();

        $data = $this->svc->buildSummary($filter, $visibleAoCodes);

        $scope = $filter['scope'] ?? null;
        $detailRows = $this->svc->detailByScope($scope, $filter, $visibleAoCodes);

        $scopeMeta = match ($scope) {
            'r0_30'      => ['title' => 'Detail RS Baru (R0–30)', 'desc' => 'Restruktur 0–30 hari sejak last_restructure_date.'],
            'r0_30_dpd'  => ['title' => 'Detail R0–30 sudah DPD > 0', 'desc' => 'Restruktur 0–30 hari dan sudah menunggak.'],
            'high_risk'  => ['title' => 'Detail RS High Risk', 'desc' => 'DPD ≥ 15 atau Kolek ≥ 3 atau Frek ≥ 2.'],
            'kritis'     => ['title' => 'Detail RS Kritis', 'desc' => 'DPD ≥ 60 atau Kolek ≥ 4.'],
            default      => ['title' => null, 'desc' => null],
        };

        return view('rs.monitoring.index', array_merge($data, [
            'latestDate' => $latestDate, // optional kalau blade perlu
            'scope'      => $scope,
            'scopeMeta'  => $scopeMeta,
            'detailRows' => $detailRows,
            'visibleAoCodes' => $visibleAoCodes,
        ]));
    }

    /**
     * ✅ RBAC Visible AO Codes (meniru pola EWS Summary)
     * Return:
     * - null => boleh lihat semua (pimpinan)
     * - []   => tidak boleh lihat apa pun
     * - [..] => daftar ao_code yang boleh dilihat
     */
    public function visibleAoCodes(): ?array
    {
        $u = auth()->user();
        if (!$u) return [];

        // ✅ Top/Management yang boleh lihat semua (sesuai enum: Kabag/PE ke atas)
        if (method_exists($u, 'hasAnyRole') && $u->hasAnyRole([
            'DIREKSI','DIR','KOM',
            'KABAG','KBL','KBO','KTI','KBF','PE',
        ])) {
            return null; // ALL
        }

        // ✅ Field staff: hanya dirinya
        if (method_exists($u, 'hasAnyRole') && $u->hasAnyRole([
            'AO','BE','FE','SO','RO','SA'
        ])) {
            $code = $u->employee_code ? trim((string)$u->employee_code) : '';
            if ($code === '') return [];

            return $this->normalizeAoCodes([$code]);
        }

        // ✅ Supervisor level TL + KASI: ambil bawahan
        if (method_exists($u, 'hasAnyRole') && $u->hasAnyRole([
            'TL','TLL','TLF','TLR','TLRO','TLSO','TLFE','TLBE','TLUM',
            'KSLU','KSO','KSA','KSF','KSD','KSLR','KSFE','KSBE',
        ])) {
            if (!class_exists(\App\Models\OrgAssignment::class)) return [];

            $codes = \App\Models\OrgAssignment::query()
                ->where('leader_id', $u->id)
                ->join('users', 'users.id', '=', 'org_assignments.user_id')
                ->whereNotNull('users.employee_code')
                ->pluck('users.employee_code')
                ->map(fn($v) => trim((string)$v))
                ->filter()
                ->unique()
                ->values()
                ->all();

            return $this->normalizeAoCodes($codes);
        }

        // Default aman
        return [];
    }

    /**
     * Normalisasi agar MATCH dengan loan_accounts.ao_code yang kadang:
     * - "30" vs "000030"
     * - "0030" vs "000030"
     *
     * Strategi:
     * - simpan original
     * - simpan versi tanpa leading zero
     * - simpan versi pad 6 digit (kalau numeric)
     */
    protected function normalizeAoCodes(array $codes): array
    {
        $out = [];

        foreach ($codes as $c) {
            $c = strtoupper(trim((string) $c));
            if ($c === '') continue;

            $out[] = $c;

            // tanpa leading zero
            $noZero = ltrim($c, '0');
            if ($noZero !== '') $out[] = $noZero;

            // pad 6 digit jika numeric
            if (ctype_digit($noZero)) {
                $out[] = str_pad($noZero, 6, '0', STR_PAD_LEFT);
            }
        }

        $out = array_values(array_unique(array_filter($out)));
        return $out;
    }

    public function updateActionStatus(Request $request)
    {
        $payload = $request->validate([
            'loan_account_id' => ['required','integer'],
            'position_date'   => ['required','date'],
            'status'          => ['required', Rule::in(['none','contacted','visit','done'])],
            'channel'         => ['nullable', Rule::in(['wa','call','visit','other'])],
            'note'            => ['nullable','string','max:255'],
            'back'            => ['nullable','string'],
        ]);

        $this->svc->upsertActionStatus(
            (int)$payload['loan_account_id'],
            $payload['position_date'],
            $payload['status'],
            $payload['channel'] ?? null,
            $payload['note'] ?? null,
            auth()->id()
        );

        $back = $payload['back'] ?? url()->previous();
        return redirect($back)->with('status', 'Status action berhasil diupdate.');
    }
}
