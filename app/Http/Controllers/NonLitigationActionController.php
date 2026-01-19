<?php

namespace App\Http\Controllers;

use App\Models\ActionSchedule;
use App\Models\CaseAction;
use App\Models\NplCase;
use App\Models\NonLitigationAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NonLitigationActionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(NplCase $case)
    {
        $this->authorize('viewAny', NonLitigationAction::class);

        $case->load('loanAccount');

        $items = NonLitigationAction::query()
            ->where('npl_case_id', $case->id)
            ->orderByDesc('id')
            ->get();

        return view('nonlit.index', compact('case', 'items'));
    }

    public function create(NplCase $case)
    {
        $this->authorize('create', [NonLitigationAction::class, $case]);

        $types = $this->typeOptions();
        return view('nonlit.create', compact('case', 'types'));
    }

    public function store(Request $request, NplCase $case)
    {
        $this->authorize('create', [NonLitigationAction::class, $case]);

        $exists = $case->legalProposals()
            ->whereIn('status', ['pending_tl','pending_kasi'])
            ->exists();

        if ($exists) {
            return back()->with('status', 'Masih ada usulan legal yang menunggu approval.');
        }

        $data = $this->validateDraft($request);

        $user = Auth::user();

        $data['npl_case_id']      = $case->id;
        $data['proposed_by']      = $user?->id;
        $data['proposed_by_name'] = $user?->name ?? $user?->username ?? null;
        $data['proposal_at']      = now();
        $data['status']           = NonLitigationAction::STATUS_DRAFT;

        // set needs_tl_approval saat create (biar konsisten)
        if (!array_key_exists('needs_tl_approval', $data) || is_null($data['needs_tl_approval'])) {
            $data['needs_tl_approval'] = $this->needsTlApprovalForUser((int) $user?->id) ? 1 : 0;
        }

        $nonLit = NonLitigationAction::create($data);

        return redirect()
            ->route('nonlit.show', $nonLit)
            ->with('success', 'Usulan Non-Litigasi berhasil dibuat (Draft).');
    }

    public function show(NonLitigationAction $nonLit)
    {
        $this->authorize('view', $nonLit);

        $case  = NplCase::with('loanAccount')->findOrFail($nonLit->npl_case_id);
        $types = $this->typeOptions();

        return view('nonlit.show', compact('nonLit', 'case', 'types'));
    }

    public function edit(NonLitigationAction $nonLit)
    {
        $this->authorize('update', $nonLit);

        $this->ensureEditable($nonLit);

        $case  = NplCase::with('loanAccount')->findOrFail($nonLit->npl_case_id);
        $types = $this->typeOptions();

        return view('nonlit.edit', compact('nonLit', 'case', 'types'));
    }

    public function update(Request $request, NonLitigationAction $nonLit)
    {
        $this->authorize('update', $nonLit);

        $this->ensureEditable($nonLit);

        $data = $this->validateDraft($request);

        // kalau user update draft, kita refresh needs_tl_approval agar tidak stale
        if (!array_key_exists('needs_tl_approval', $data) || is_null($data['needs_tl_approval'])) {
            $data['needs_tl_approval'] = $this->needsTlApprovalForUser((int) $nonLit->proposed_by) ? 1 : 0;
        }

        $nonLit->update($data);

        return redirect()
            ->route('nonlit.show', $nonLit)
            ->with('success', 'Draft Non-Litigasi berhasil diperbarui.');
    }

    public function submit(NonLitigationAction $nonLit)
    {
        $this->authorize('update', $nonLit);

        $this->ensureEditable($nonLit);

        if (!$nonLit->action_type || !$nonLit->proposal_summary) {
            return back()->with('error', 'Lengkapi minimal: Jenis tindakan & Ringkasan usulan sebelum submit.');
        }

        DB::transaction(function () use ($nonLit) {

            if ($nonLit->status !== NonLitigationAction::STATUS_DRAFT) {
                return;
            }

            // set needs_tl_approval kalau belum terisi
            if (is_null($nonLit->needs_tl_approval)) {
                $nonLit->needs_tl_approval = $this->needsTlApprovalForUser((int) $nonLit->proposed_by) ? 1 : 0;
            }

            // ✅ status submit -> pending_tl / pending_kasi (skip TL)
            $nonLit->status = ((int)$nonLit->needs_tl_approval === 1)
                ? NonLitigationAction::STATUS_PENDING_TL
                : NonLitigationAction::STATUS_PENDING_KASI;

            $nonLit->save();

            // timeline SUBMITTED (idempotent)
            $user = Auth::user();

            $existing = CaseAction::query()
                ->where('npl_case_id', $nonLit->npl_case_id)
                ->where('source_system', 'non_litigation_submit')
                ->where('source_ref_id', (string) $nonLit->id)
                ->first();

            if (!$existing) {
                $ca = CaseAction::create([
                    'npl_case_id'   => $nonLit->npl_case_id,
                    'user_id'       => $user?->id,
                    'source_system' => 'non_litigation_submit',
                    'source_ref_id' => (string) $nonLit->id,

                    'action_at'   => now(),
                    'action_type' => 'non_litigasi',
                    'description' => $this->buildNonLitEventDescription($nonLit, 'SUBMITTED'),
                    'result'      => 'SUBMITTED',

                    'next_action' => ((int)$nonLit->needs_tl_approval === 1)
                        ? 'Menunggu approval TL (Non-Litigasi)'
                        : 'Menunggu approval KASI (Non-Litigasi)',

                    'next_action_due' => null,

                    'meta' => [
                        'event'                    => 'submitted',
                        'non_litigation_action_id' => $nonLit->id,
                        'non_litigation_type'      => $nonLit->action_type,
                        'needs_tl_approval'         => (int)$nonLit->needs_tl_approval,
                    ],
                ]);

                if (!$nonLit->case_action_id) {
                    $nonLit->case_action_id = $ca->id;
                    $nonLit->save();
                }
            } else {
                if (!$nonLit->case_action_id) {
                    $nonLit->case_action_id = $existing->id;
                    $nonLit->save();
                }
            }
        });

        return back()->with('success', 'Usulan Non-Litigasi berhasil disubmit & tercatat di Timeline.');
    }

    /**
     * ✅ APPROVE/REJECT sekarang WAJIB lewat Supervisi (TL/KASI).
     * Controller ini khusus AO / input usulan.
     */
    public function approve(Request $request, NonLitigationAction $nonLit)
    {
        abort(403, 'Approval Non-Litigasi dilakukan lewat menu Supervisi (TL/KASI).');
    }

    public function reject(Request $request, NonLitigationAction $nonLit)
    {
        abort(403, 'Approval Non-Litigasi dilakukan lewat menu Supervisi (TL/KASI).');
    }

    // =========================
    // Helpers
    // =========================

    private function validateDraft(Request $request): array
    {
        $data = $request->validate([
            'action_type'         => ['required', 'string', 'max:50'],
            'proposal_summary'    => ['required', 'string', 'max:255'],
            'proposal_detail'     => ['nullable'],
            'commitment_amount'   => ['nullable', 'numeric', 'min:0'],
            'installment_plan'    => ['nullable'],
            'effective_date'      => ['nullable', 'date'],
            'monitoring_next_due' => ['nullable', 'date'],
            'meta'                => ['nullable'],
        ]);

        foreach (['proposal_detail','installment_plan','meta'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === '') $data[$k] = null;
        }

        return $data;
    }

    private function ensureEditable(NonLitigationAction $nonLit): void
    {
        if ($nonLit->status !== NonLitigationAction::STATUS_DRAFT) {
            abort(403, 'Tidak bisa edit selain status Draft.');
        }
    }

    private function typeOptions(): array
    {
        return [
            NonLitigationAction::TYPE_RESTRUCT          => 'Restrukturisasi',
            NonLitigationAction::TYPE_RESCHEDULE        => 'Rescheduling (Perpanjang Tenor)',
            NonLitigationAction::TYPE_RECONDITION       => 'Reconditioning (Ubah Syarat/Bunga)',
            NonLitigationAction::TYPE_NOVASI            => 'Novasi / Alih Debitur',
            NonLitigationAction::TYPE_SETTLEMENT        => 'Settlement / Kesepakatan Pembayaran',
            NonLitigationAction::TYPE_PTP               => 'Janji Bayar (PTP)',
            NonLitigationAction::TYPE_DISCOUNT_INTEREST => 'Penurunan Bunga',
            NonLitigationAction::TYPE_WAIVE_PENALTY     => 'Penghapusan Denda',
        ];
    }

    private function buildNonLitEventDescription(NonLitigationAction $nonLit, string $event): string
    {
        $type    = strtoupper((string) $nonLit->action_type);
        $summary = trim((string) $nonLit->proposal_summary);

        $parts = [];
        $parts[] = "NON-LITIGASI {$event} ({$type})";
        if ($summary !== '') $parts[] = "Ringkasan: {$summary}";

        if (!empty($nonLit->proposal_detail)) {
            $detail = is_array($nonLit->proposal_detail)
                ? json_encode($nonLit->proposal_detail, JSON_UNESCAPED_UNICODE)
                : (string) $nonLit->proposal_detail;
            $detail = trim($detail);
            if ($detail !== '') $parts[] = "Detail: {$detail}";
        }

        return implode("\n", $parts);
    }

    protected function needsTlApprovalForUser(int $userId): bool
    {
        $oa = \App\Models\OrgAssignment::query()
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->first();

        if (!$oa) return true;

        return in_array(strtoupper((string)$oa->leader_role), ['TL', 'TLL', 'TLR'], true);
    }
}
