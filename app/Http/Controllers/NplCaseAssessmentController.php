<?php

namespace App\Http\Controllers;

use App\Models\NplCase;
use Illuminate\Http\Request;

class NplCaseAssessmentController extends Controller
{
    public function store(Request $request, NplCase $case)
    {
        $this->authorize('updateAssessment', $case);

        $data = $request->validate([
            'assessment' => ['required', 'string', 'max:10000'],
        ]);

        $case->assessment = $data['assessment'];
        $case->assessment_updated_by = auth()->id();
        $case->assessment_updated_at = now();
        $case->save();

        return back()->with('status', 'Assessment berhasil disimpan.');
    }
}
