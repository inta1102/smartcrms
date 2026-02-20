<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunityController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string)$request->get('q', ''));
        $status = (string)$request->get('status', 'active');
        $city = trim((string)$request->get('city', ''));

        $rows = DB::table('communities')
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                      ->orWhere('pic_name', 'like', "%{$q}%")
                      ->orWhere('pic_phone', 'like', "%{$q}%")
                      ->orWhere('code', 'like', "%{$q}%");
                });
            })
            ->when($status !== '', fn($qq) => $qq->where('status', $status))
            ->when($city !== '', fn($qq) => $qq->where('city', 'like', "%{$city}%"))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('kpi.communities.index', compact('rows','q','status','city'));
    }

    public function create()
    {
            
        return view('kpi.communities.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['nullable','string','max:30'],
            'name' => ['required','string','max:190'],
            'type' => ['nullable','string','max:30'],
            'segment' => ['nullable','string','max:30'],
            'address' => ['nullable','string','max:190'],
            'village' => ['nullable','string','max:60'],
            'district' => ['nullable','string','max:60'],
            'city' => ['nullable','string','max:60'],
            'pic_name' => ['nullable','string','max:190'],
            'pic_phone' => ['nullable','string','max:30'],
            'pic_position' => ['nullable','string','max:60'],
            'notes' => ['nullable','string'],
            'status' => ['nullable','in:active,inactive'],
        ]);

        $data['created_by'] = $request->user()?->id;
        $data['status'] = $data['status'] ?? 'active';
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('communities')->insertGetId($data);

        return redirect()->route('kpi.communities.show', $id)
            ->with('success', 'Komunitas berhasil dibuat.');
    }

    public function show(int $community)
    {
        $c = DB::table('communities')->where('id', $community)->first();
        abort_if(!$c, 404);

        $handlings = DB::table('community_handlings as h')
            ->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->select([
                'h.*',
                'u.name as user_name',
                'u.ao_code as user_ao_code',
                'u.level as user_level',
            ])
            ->where('h.community_id', $community)
            ->orderByDesc('h.period_from')
            ->orderBy('h.role')
            ->get();

        // kandidat user untuk KBL assign
        $usersAoSo = DB::table('users')
            ->select(['id','name','ao_code','level'])
            ->whereIn('level', ['AO','SO'])
            ->orderBy('level')
            ->orderBy('name')
            ->get();

        return view('kpi.communities.show', compact('c','handlings','usersAoSo'));
    }

    public function edit(int $community)
    {
        $c = DB::table('communities')->where('id', $community)->first();
        abort_if(!$c, 404);

        return view('kpi.communities.edit', compact('c'));
    }

    public function update(Request $request, int $community)
    {
        $c = DB::table('communities')->where('id', $community)->first();
        abort_if(!$c, 404);

        $data = $request->validate([
            'code' => ['nullable','string','max:30'],
            'name' => ['required','string','max:190'],
            'type' => ['nullable','string','max:30'],
            'segment' => ['nullable','string','max:30'],
            'address' => ['nullable','string','max:190'],
            'village' => ['nullable','string','max:60'],
            'district' => ['nullable','string','max:60'],
            'city' => ['nullable','string','max:60'],
            'pic_name' => ['nullable','string','max:190'],
            'pic_phone' => ['nullable','string','max:30'],
            'pic_position' => ['nullable','string','max:60'],
            'notes' => ['nullable','string'],
            'status' => ['required','in:active,inactive'],
        ]);

        $data['updated_at'] = now();

        DB::table('communities')->where('id', $community)->update($data);

        return redirect()->route('kpi.communities.show', $community)
            ->with('success', 'Komunitas berhasil diupdate.');
    }
}
