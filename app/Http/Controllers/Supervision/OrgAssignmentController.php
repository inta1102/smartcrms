<?php

namespace App\Http\Controllers\Supervision;

use App\Http\Controllers\Controller;
use App\Models\OrgAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class OrgAssignmentController extends Controller
{
    public function __construct()
    {
        // route param: {assignment}
        $this->authorizeResource(OrgAssignment::class, 'assignment');
    }

    public function index()
    {
        $rows = OrgAssignment::query()
            ->with(['staff','leader'])
            ->orderByDesc('is_active')
            ->orderByDesc('effective_from')
            ->orderByRaw("FIELD(leader_role, 'DIR','KABAG','PE','KASI','TL')")
            ->orderBy('user_id')
            ->paginate(20)
            ->withQueryString();

        return view('supervision.org.assignments.index', compact('rows'));
    }

    public function create()
    {
        $staffUsers  = User::orderBy('name')->get(['id','name','level']);
        $leaderUsers = User::orderBy('name')->get(['id','name','level']);

        $units = [
            'lending'     => 'Lending',
            'remedial'    => 'Remedial',
            'operasional' => 'Operasional',
            'audit'       => 'Audit',
            'mr'          => 'Manajemen Risiko',
            'compliance'  => 'Kepatuhan',
            'ti'          => 'Teknologi Informasi',
        ];

        $leaderRolesAll = ['TL','KASI','KABAG','PE','DIR'];
        $oversightUnits = ['audit','mr','compliance'];

        return view('supervision.org.assignments.create', compact(
            'staffUsers','leaderUsers','units','leaderRolesAll','oversightUnits'
        ));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id'        => ['required','exists:users,id'],
            'leader_id'      => ['required','exists:users,id','different:user_id'],
            'unit_code'      => ['nullable','string','max:50'],
            'leader_role'    => ['required','string','max:50'],
            'effective_from' => ['required','date'],
        ]);

        $unitCode   = $this->normalizeUnit($data['unit_code'] ?? null);
        $leaderRole = strtoupper(trim((string) $data['leader_role']));
        $oversightUnits = ['audit','mr','compliance'];

        $allowedRoles = in_array($unitCode, $oversightUnits, true)
            ? ['PE','DIR']
            : ['TL','KASI','KABAG','DIR'];

        if (!in_array($leaderRole, $allowedRoles, true)) {
            return back()->withErrors(['leader_role' => 'Leader role tidak valid untuk unit ini.'])->withInput();
        }

        DB::transaction(function () use ($data, $unitCode, $leaderRole) {

            // end assignment aktif sebelumnya (user + unit sama)
            OrgAssignment::query()
                ->where('user_id', $data['user_id'])
                ->when($unitCode !== null,
                    fn($q) => $q->where('unit_code', $unitCode),
                    fn($q) => $q->whereNull('unit_code')
                )
                ->where('is_active', 1)
                ->lockForUpdate()
                ->update([
                    'is_active'    => 0,
                    'effective_to' => now()->toDateString(),
                    'active_key'   => null,
                ]);

            $activeKey = $this->buildActiveKey(
                (int)$data['user_id'],
                (int)$data['leader_id'],
                $unitCode,
                $leaderRole
            );

            OrgAssignment::create([
                'user_id'        => (int)$data['user_id'],
                'leader_id'      => (int)$data['leader_id'],
                'leader_role'    => $leaderRole,
                'unit_code'      => $unitCode,
                'effective_from' => Carbon::parse($data['effective_from'])->toDateString(),
                'effective_to'   => null,
                'is_active'      => 1,
                'active_key'     => $activeKey,
                'created_by'     => auth()->id(),
            ]);
        });

        return redirect()
            ->route('supervision.org.assignments.index')
            ->with('success', 'Assignment berhasil dibuat.');
    }

    public function edit(OrgAssignment $assignment)
    {
        $staffUsers  = User::orderBy('name')->get(['id','name','level']);
        $leaderUsers = User::orderBy('name')->get(['id','name','level']);

        $units = [
            'lending'     => 'Lending',
            'remedial'    => 'Remedial',
            'operasional' => 'Operasional',
            'audit'       => 'Audit',
            'mr'          => 'Manajemen Risiko',
            'compliance'  => 'Kepatuhan',
            'ti'          => 'Teknologi Informasi',
        ];

        $leaderRolesAll = ['TL','KASI','KABAG','PE','DIR'];
        $oversightUnits = ['audit','mr','compliance'];

        return view('supervision.org.assignments.edit', compact(
            'assignment','staffUsers','leaderUsers','units','leaderRolesAll','oversightUnits'
        ));
    }

    public function update(Request $request, OrgAssignment $assignment)
    {
        $data = $request->validate([
            'leader_id'      => ['required','exists:users,id','different:assignment.user_id'],
            'unit_code'      => ['nullable','string','max:50'],
            'leader_role'    => ['required','string','max:50'],
            'effective_from' => ['required','date'],
            'is_active'      => ['nullable','boolean'],
            'effective_to'   => ['nullable','date','after_or_equal:effective_from'],
        ]);

        $unitCode   = $this->normalizeUnit($data['unit_code'] ?? null);
        $leaderRole = strtoupper(trim((string) $data['leader_role']));
        $oversightUnits = ['audit','mr','compliance'];

        $allowedRoles = in_array($unitCode, $oversightUnits, true)
            ? ['PE','DIR']
            : ['TL','KASI','KABAG','DIR'];

        if (!in_array($leaderRole, $allowedRoles, true)) {
            return back()->withErrors(['leader_role' => 'Leader role tidak valid untuk unit ini.'])->withInput();
        }

        $isActive = array_key_exists('is_active', $data)
            ? (bool)$data['is_active']
            : (bool)$assignment->is_active;

        if (!$isActive && empty($data['effective_to'])) {
            $data['effective_to'] = now()->toDateString();
        }

        DB::transaction(function () use ($assignment, $data, $unitCode, $leaderRole, $isActive) {

            $row = OrgAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            $row->leader_id      = (int)$data['leader_id'];
            $row->leader_role    = $leaderRole;
            $row->unit_code      = $unitCode;
            $row->effective_from = Carbon::parse($data['effective_from'])->toDateString();
            $row->effective_to   = $data['effective_to'] ?? $row->effective_to;
            $row->is_active      = $isActive ? 1 : 0;

            if ($row->is_active) {
                $row->active_key = $this->buildActiveKey(
                    (int)$row->user_id,
                    (int)$row->leader_id,
                    $row->unit_code,
                    (string)$row->leader_role
                );
                $row->effective_to = null;
            } else {
                $row->active_key = null;
            }

            $row->save();

            if ($row->is_active) {
                OrgAssignment::query()
                    ->where('user_id', $row->user_id)
                    ->where('id', '!=', $row->id)
                    ->when($row->unit_code !== null,
                        fn($q) => $q->where('unit_code', $row->unit_code),
                        fn($q) => $q->whereNull('unit_code')
                    )
                    ->where('is_active', 1)
                    ->lockForUpdate()
                    ->update([
                        'is_active'    => 0,
                        'effective_to' => now()->toDateString(),
                        'active_key'   => null,
                    ]);
            }
        });

        return redirect()
            ->route('supervision.org.assignments.index')
            ->with('success', 'Assignment berhasil diupdate.');
    }

    public function end(OrgAssignment $assignment)
    {
        $this->authorize('update', $assignment);

        if (!(int)$assignment->is_active) {
            return back()->with('success', 'Assignment sudah nonaktif.');
        }

        DB::transaction(function () use ($assignment) {
            $row = OrgAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            $row->is_active    = 0;
            $row->effective_to = $row->effective_to ?? now()->toDateString();
            $row->active_key   = null;
            $row->save();
        });

        return redirect()
            ->route('supervision.org.assignments.index')
            ->with('success', 'Assignment berhasil diakhiri.');
    }

    private function normalizeUnit(?string $unit): ?string
    {
        $u = trim((string)$unit);
        return $u === '' ? null : strtolower($u);
    }

    private function buildActiveKey(int $userId, int $leaderId, ?string $unitCode, string $leaderRole): string
    {
        $unit = $unitCode ?? '_';
        return "{$userId}|{$leaderId}|{$unit}|{$leaderRole}";
    }
}
