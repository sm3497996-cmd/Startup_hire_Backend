<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Employer ke "Save Resume" ka logic.
 * Shortlist se bilkul alag: sirf saved_resumes table use hoti hai.
 */
class SavedResumeController extends Controller
{
    // GET /api/interactions/saved-resumes -> is employer ne jin candidates ke resume save kiye
    public function index(Request $request)
    {
        $ids = DB::table('saved_resumes')
            ->where('employer_id', $request->user()->id)
            ->pluck('candidate_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        return response()->json(['success' => true, 'saved' => $ids]);
    }

    // POST /api/interactions/saved-resumes/toggle  { candidate_id }
    public function toggle(Request $request)
    {
        $data = $request->validate([
            'candidate_id' => 'required|integer',
        ]);

        $employerId = (int) $request->user()->id;
        $candidateId = (int) $data['candidate_id'];

        $row = DB::table('saved_resumes')
            ->where('employer_id', $employerId)
            ->where('candidate_id', $candidateId);

        if ($row->exists()) {
            $row->delete();
            $active = false;
        } else {
            DB::table('saved_resumes')->insert([
                'employer_id'  => $employerId,
                'candidate_id' => $candidateId,
                'created_at'   => now(),
            ]);
            $active = true;
        }

        return response()->json(['success' => true, 'active' => $active]);
    }
}