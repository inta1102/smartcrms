<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunityHandlingController extends Controller
{
    public function store(Request $request, int $community)
    {
        $user = $request->user();
        abort_if(!$user, 401);

        $c = DB::table('communities')->where('id', $community)->first();
        abort_if(!$c, 404);

        $data = $request->validate([
            'role' => ['nullable','in:AO,SO'], // KBL boleh pilih
            'user_id' => ['nullable','integer'],
            'period_from' => ['required','date'],
            'period_to' => ['nullable','date'],
        ]);

        $myLevel = $user->roleValue();

        // default: AO/SO assign dirinya sendiri + role mengikuti level
        $role = $data['role'] ?? ($myLevel === 'SO' ? 'SO' : 'AO');
        $targetUserId = (int)($data['user_id'] ?? $user->id);

        // guard: AO tidak boleh assign SO, SO tidak boleh assign AO
        if ($myLevel === 'AO') {
            $role = 'AO';
            $targetUserId = (int)$user->id;
        }
        if ($myLevel === 'SO') {
            $role = 'SO';
            $targetUserId = (int)$user->id;
        }

        // KBL boleh pilih user_id + role
        if ($myLevel === 'KBL') {
            $role = $data['role'] ?? 'AO';
            $targetUserId = (int)($data['user_id'] ?? 0);
            abort_if($targetUserId <= 0, 422);
        }

        $periodFrom = Carbon::parse($data['period_from'])->startOfMonth()->toDateString();
        $periodTo = !empty($data['period_to']) ? Carbon::parse($data['period_to'])->startOfMonth()->toDateString() : null;

        $payload = [
            'community_id' => $community,
            'user_id' => $targetUserId,
            'role' => $role,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'input_by' => $user->id,
            'updated_at' => now(),
        ];

        $exists = DB::table('community_handlings')
            ->where('community_id', $community)
            ->where('user_id', $targetUserId)
            ->where('role', $role)
            ->where('period_from', $periodFrom)
            ->exists();

        if ($exists) {
            DB::table('community_handlings')
                ->where('community_id', $community)
                ->where('user_id', $targetUserId)
                ->where('role', $role)
                ->where('period_from', $periodFrom)
                ->update($payload);
        } else {
            $payload['created_at'] = now();
            DB::table('community_handlings')->insert($payload);
        }

        return back()->with('success', 'Handling komunitas tersimpan.');
    }

    public function end(Request $request, int $handling)
    {
        $user = $request->user();
        abort_if(!$user, 401);

        $row = DB::table('community_handlings')->where('id', $handling)->first();
        abort_if(!$row, 404);

        $myLevel = $user->roleValue();

        // AO/SO hanya boleh end record miliknya, KBL boleh end semua
        if ($myLevel !== 'KBL') {
            abort_if((int)$row->user_id !== (int)$user->id, 403);
        }

        $data = $request->validate([
            'period_to' => ['required','date'],
        ]);

        $periodTo = Carbon::parse($data['period_to'])->startOfMonth()->toDateString();

        DB::table('community_handlings')->where('id', $handling)->update([
            'period_to' => $periodTo,
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Handling diakhiri.');
    }
}
