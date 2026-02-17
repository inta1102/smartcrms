<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\KpiThreshold;
use Illuminate\Http\Request;

class KpiThresholdController extends Controller
{
    public function index()
    {
        $items = KpiThreshold::query()->orderBy('metric')->get();
        return view('kpi.thresholds.index', compact('items'));
    }

    public function edit(KpiThreshold $threshold)
    {
        return view('kpi.thresholds.edit', ['t' => $threshold]);
    }

    public function update(Request $request, KpiThreshold $threshold)
    {
        $data = $request->validate([
            'title' => ['required','string','max:100'],
            'direction' => ['required','in:higher_is_better,lower_is_better'],
            'green_min' => ['nullable','numeric'],
            'yellow_min' => ['nullable','numeric'],
            'is_active' => ['nullable','boolean'],
        ]);

        $threshold->fill($data);
        $threshold->is_active = (bool)($request->boolean('is_active'));
        $threshold->updated_by = $request->user()?->id;
        $threshold->save();

        return redirect()
            ->route('kpi.thresholds.index')
            ->with('success', 'Threshold berhasil diupdate.');
    }
}
