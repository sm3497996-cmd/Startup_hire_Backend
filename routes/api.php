<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ResumeController;
use App\Http\Controllers\InteractionController;
use App\Http\Controllers\AuthController;
use App\Services\CandidateScoreService;

// ---------- Purane routes ----------
Route::middleware('legacy:candidate,superadmin')->post('/resume', [ResumeController::class, 'store']);
Route::middleware('legacy:candidate,employer,superadmin')->get('/resume/{candidateId}', [ResumeController::class, 'show']);

// ---------- Login ----------
Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

// ---------- Like / Save / Comment / Report ----------
Route::middleware('auth:sanctum')->prefix('interactions')->group(function () {
    Route::post('/{type}/toggle', [InteractionController::class, 'toggle'])->whereIn('type', ['like', 'save']);
    Route::post('/comment', [InteractionController::class, 'comment']);
    Route::post('/report', [InteractionController::class, 'report']);
    Route::get('/my-state', [InteractionController::class, 'myState']);
    Route::get('/comments/{candidateId}', [InteractionController::class, 'listComments']);
    Route::get('/my-activity', [InteractionController::class, 'myActivity']);
    Route::get('/admin-activity', [InteractionController::class, 'adminActivity']);
    Route::get('/candidates', [InteractionController::class, 'candidates']);
});
Route::middleware('superadmin')->get('/admin/candidate-score/{id}', function ($id) {
    return response()->json(['success' => true] + app(CandidateScoreService::class)->score($id));
});
Route::middleware('legacy:employer')->get('/employer/candidate-score/{id}', function ($id, \Illuminate\Http\Request $request) {
    $u = $request->attributes->get('legacy_user');
    $ok = \Illuminate\Support\Facades\DB::table('applications')
        ->join('jobs', 'jobs.id', '=', 'applications.job_id')
        ->where('applications.candidate_id', $id)
        ->where('jobs.posted_by', $u['id'])
        ->exists();
    if (!$ok) {
        return response()->json(['success' => false, 'message' => 'Not your applicant.'], 403);
    }
    return response()->json(['success' => true] + app(CandidateScoreService::class)->score($id));
});