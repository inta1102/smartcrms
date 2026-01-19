<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Models\NplCase;
use App\Models\LegalAction;
use App\Models\LegalCase;
use App\Services\Legal\LitigationEligibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LegalEscalationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function start(Request $request, NplCase $case, LitigationEligibilityService $elig)
    {
        // ✅ Policy/Gate eskalasi (optional tapi disarankan)
        // $this->authorize('startLitigation', $case);

        // ✅ RULE BACKEND (yang kamu minta)
        $eval = $elig->evaluate($case);

        if (!$eval['ok']) {
            $msg = collect($eval['checks'])
                ->filter(fn($c) => !($c['ok'] ?? false))
                ->map(fn($c) => ($c['label'] ?? 'Rule') . ': ' . ($c['reason'] ?? 'Tidak memenuhi'))
                ->implode(' | ');

            return back()->withErrors(['litigation' => "Belum memenuhi syarat litigasi. {$msg}"]);
        }

        // ✅ Create legal action (transaction)
        DB::transaction(function () use ($case) {

            // 1) pastikan legal_case ada (atau buat)
            $legalCase = LegalCase::firstOrCreate(
                ['npl_case_id' => $case->id],
                [
                    'status' => 'open',
                    'escalation_reason' => 'Eskalasi litigasi dari CRMS',
                    'created_by' => auth()->id(),
                ]
            );

            // 2) buat legal action awal (contoh: somasi / litigasi)
            // Kamu bisa pilih default action_type = 'somasi' dulu, bukan langsung gugatan.
            LegalAction::create([
                'legal_case_id' => $legalCase->id,
                'action_type'   => 'somasi',      // atau 'litigasi' sesuai master kamu
                'status'        => 'draft',
                'start_at'      => now(),
                'summary'       => 'Eskalasi litigasi (auto)',
                'notes'         => 'Dibuat otomatis setelah syarat litigasi terpenuhi.',
                'created_by'    => auth()->id(),
                'updated_by'    => auth()->id(),
            ]);
        });

        return back()->with('success', '✅ Eskalasi litigasi berhasil dibuat.');
    }
}
