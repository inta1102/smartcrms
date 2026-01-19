<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Models\LegalActionProposal;
use Illuminate\Http\Request;
use App\Models\NplCase;
use Illuminate\Support\Facades\DB;
use App\Models\OrgAssignment;

use App\Models\LegalAction;
use App\Models\LegalCase;


class LegalActionProposalController extends Controller
{
    public function index(Request $request) 
    {
        $user  = auth()->user();
        $today = now()->toDateString();

        $q = LegalActionProposal::query()
            ->with(['nplCase.loanAccount', 'proposer']);

        // =========================================
        // 1) Visibility by Role (default status)
        //    - Default status hanya dipakai kalau request tidak mengirim status
        // =========================================
        $statusFromRequest = $request->filled('status') ? trim((string)$request->status) : null;

        // (opsional) Alias status lama / agar URL lebih fleksibel
        // - Kalau kamu mau: "pending_kasi" dianggap sama dengan "approved_tl"
        // - Kalau kamu tidak pakai pending_kasi sama sekali, boleh hapus blok alias ini.
        if ($statusFromRequest === LegalActionProposal::STATUS_PENDING_KASI) {
            $statusFromRequest = LegalActionProposal::STATUS_APPROVED_TL;
        }

        // AO / FE / SO / RO: hanya proposal sendiri
        if ($user->hasAnyRole(['AO','FE','SO','RO'])) {
            $q->where('proposed_by', $user->id);

            if (!$statusFromRequest) {
                // default AO: tampilkan semua milik sendiri
                // kalau mau default tertentu, isi di sini
            }
        }

        // TL: hanya yg butuh approval TL DAN hanya dari bawahan
        elseif ($user->hasAnyRole(['TL','TLL','TLR'])) {

            // ambil bawahan TL (aktif & effective date valid)
            $subordinateIds = OrgAssignment::query()
                ->where('leader_id', $user->id)
                ->where('is_active', 1)
                ->whereDate('effective_from', '<=', $today)
                ->where(function ($x) use ($today) {
                    $x->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $today);
                })
                ->pluck('user_id')
                ->all();

            // kalau TL belum punya bawahan, kunci hasil kosong (aman)
            if (empty($subordinateIds)) {
                $q->whereRaw('1=0');
            } else {
                $q->whereIn('proposed_by', $subordinateIds);
            }

            // default status TL: hanya pending TL (inbox TL)
            if (!$statusFromRequest) {
                $q->where('status', LegalActionProposal::STATUS_PENDING_TL);
            }
        }

        // Kasi: default yg sudah approved TL (antrian Kasi)
        elseif ($user->hasAnyRole(['KSR','KSL'])) {
            if (!$statusFromRequest) {
                $q->where('status', LegalActionProposal::STATUS_APPROVED_TL);
            }

            // NOTE:
            // Visibility scope Kasi idealnya tetap dipaksa di query (bukan hanya di approveKasi()).
            // Tapi karena kamu sudah punya helper isWithinKasiScope() yang butuh user_id per proposal,
            // enforce scope query-nya biasanya pakai mapping tabel struktur org.
            // Kalau kamu punya tabel pemetaan "Kasi -> TL -> AO", nanti kita bisa buat whereIn(proposed_by, allowedIds).
        }

        // BE: siap dieksekusi
        elseif ($user->hasRole('BE')) {
            if (!$statusFromRequest) {
                $q->where('status', LegalActionProposal::STATUS_APPROVED_KASI);
            }
        }

        // else: pimpinan/admin => boleh lihat semua (atau batasi kalau mau)

        // =========================================
        // 2) Filters (override default jika status dikirim)
        // =========================================
        if ($statusFromRequest) {
            // (opsional) whitelist status biar aman dari input aneh
            // kalau kamu sudah punya LegalActionProposal::STATUSES, pakai itu
            if (defined(LegalActionProposal::class.'::STATUSES')) {
                if (in_array($statusFromRequest, LegalActionProposal::STATUSES, true)) {
                    $q->where('status', $statusFromRequest);
                } else {
                    // kalau status tidak dikenal, kosongkan agar tidak bocor data
                    $q->whereRaw('1=0');
                }
            } else {
                // fallback: langsung pakai
                $q->where('status', $statusFromRequest);
            }
        }

        if ($request->filled('keyword')) {
            $kw = trim((string)$request->keyword);

            $q->whereHas('nplCase.loanAccount', function ($qq) use ($kw) {
                $qq->where('customer_name', 'like', "%{$kw}%")
                ->orWhere('debtor_name', 'like', "%{$kw}%")
                ->orWhere('cif', 'like', "%{$kw}%")
                ->orWhere('account_no', 'like', "%{$kw}%");
            });
        }

        $proposals = $q->latest()->paginate(15)->withQueryString();

        return view('legal.proposals.index', compact('proposals'));
    }

    public function store(Request $request, NplCase $case)
    {
        $data = $request->validate([
            'action_type' => ['required', 'string', 'max:50'],
            'reason'      => ['required', 'string', 'max:5000'],  // ✅ WAJIB biar ga null
            'notes'       => ['nullable', 'string', 'max:5000'],
        ]);

        $userId = (int) auth()->id();
        $needsTl = $this->needsTlApprovalForUser($userId); // true kalau leader_role TL/TLL/TLR

        DB::transaction(function () use ($case, $data, $userId, $needsTl) {

            $case->legalProposals()->create([
                'action_type'       => $data['action_type'],
                'reason'            => $data['reason'],            // ✅ masuk
                'notes'             => $data['notes'] ?? null,

                'proposed_by'       => $userId,
                'needs_tl_approval' => $needsTl ? 1 : 0,

                // ✅ status awal:
                // - butuh TL -> pending_tl
                // - skip TL  -> approved_tl (langsung antri Kasi)
                'status'            => $needsTl ? 'pending_tl' : 'approved_tl',

                // ✅ audit time
                'submitted_at'      => now(),

                // ✅ kalau skip TL, kita anggap "lolos TL otomatis"
                'approved_tl_at'    => $needsTl ? null : now(),
                'approved_tl_by'    => $needsTl ? null : null, // sengaja null: bukan TL beneran
            ]);
        });

        return back()->with('status', $needsTl
            ? 'Usulan legal terkirim (menunggu approval TL).'
            : 'Usulan legal terkirim (langsung ke approval Kasi).'
        );
    }

    /**
     * TRUE = butuh TL approval.
     * FALSE = skip TL (atasan langsung bukan TL: mis. KSL/KSR/Kabag/DIR).
     */
    protected function needsTlApprovalForUser(int $userId): bool
    {
        $oa = \App\Models\OrgAssignment::query()
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->first();

        if (!$oa) return true; // default aman: butuh TL

        $leaderRole = strtoupper((string) $oa->leader_role);

        // kalau leader_role memang TL level, maka butuh TL
        return in_array($leaderRole, ['TL', 'TLL', 'TLR'], true);
    }

    public function execute(Request $request, LegalActionProposal $proposal)
    {
        $user = auth()->user();
        abort_unless($user && $user->hasRole('BE'), 403);

        if ($proposal->status !== LegalActionProposal::STATUS_APPROVED_KASI) {
            return back()->with('status', 'Status proposal tidak valid untuk dieksekusi BE.');
        }

        $action = null;

        DB::transaction(function () use ($proposal, $user, &$action) {
            $p = LegalActionProposal::query()
                ->whereKey($proposal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($p->status !== LegalActionProposal::STATUS_APPROVED_KASI) {
                abort(422, 'Status proposal sudah berubah.');
            }

            // kalau sudah punya action, jangan buat lagi
            if ($p->legal_action_id) {
                $action = LegalAction::query()->whereKey($p->legal_action_id)->first();
            }

            if (!$action) {
                $legalCase = LegalCase::query()
                    ->where('npl_case_id', $p->npl_case_id)
                    ->lockForUpdate()
                    ->first();

                if (!$legalCase) {
                    abort(422, 'Legal Case belum ada. Pastikan Kasi approve sudah membuat legal case.');
                }

                $action = LegalAction::create([
                    'legal_case_id' => $legalCase->id,
                    'action_type'   => $p->action_type, // 'ht_execution'
                    'sequence_no'   => 1,
                    'status'        => 'draft',
                    'notes'         => $p->notes,
                    'meta'          => json_encode([
                        'proposal_id' => $p->id,
                        'reason'      => $p->reason,
                        'created_from'=> 'proposal',
                    ]),
                    'start_at'      => now(),
                ]);

                $p->legal_action_id = $action->id;
            }

            $p->status      = LegalActionProposal::STATUS_EXECUTED;
            $p->executed_by = (int) $user->id;
            $p->executed_at = now();
            $p->save();
        });

        // ✅ ini yang nyambung ke show() kamu
        return redirect()->route('legal-actions.ht.show', $action);
    }

}
