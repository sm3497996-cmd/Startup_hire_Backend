<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ResumeController;
use App\Http\Controllers\InteractionController;
use App\Http\Controllers\AuthController;
use App\Services\CandidateScoreService;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\SavedResumeController;
use App\Http\Controllers\ConnectionController;
// ---------- Purane routes ----------
Route::middleware('legacy:candidate,superadmin')->post('/resume', [ResumeController::class, 'store']);
Route::middleware('legacy:candidate,employer,superadmin')->get('/resume/{candidateId}', [ResumeController::class, 'show']);
Route::post('/resume/attachments', [AttachmentController::class, 'store']);
Route::delete('/resume/attachments/{id}', [AttachmentController::class, 'destroy']);

// ---------- Profile photo ----------
Route::middleware('legacy:candidate,superadmin')->post('/resume/photo', [ResumeController::class, 'uploadPhoto']);
Route::middleware('legacy:candidate,superadmin')->delete('/resume/photo', [ResumeController::class, 'deletePhoto']);

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
    Route::get('/likes/{candidateId}', [InteractionController::class, 'listLikes']);
    Route::get('/my-activity', [InteractionController::class, 'myActivity']);
    Route::get('/admin-activity', [InteractionController::class, 'adminActivity']);
    Route::get('/candidates', [InteractionController::class, 'candidates']);
    Route::get('/saved-resumes', [SavedResumeController::class, 'index']);
    Route::post('/saved-resumes/toggle', [SavedResumeController::class, 'toggle']);
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
Route::middleware('auth:sanctum')->prefix('connections')->group(function () {
    Route::get('/', [ConnectionController::class, 'index']);
    Route::get('/pending', [ConnectionController::class, 'pending']);
    Route::get('/sent', [ConnectionController::class, 'sent']);
    Route::get('/suggestions', [ConnectionController::class, 'suggestions']);
    Route::post('/send', [ConnectionController::class, 'send']);
    Route::post('/{id}/respond', [ConnectionController::class, 'respond']);
    Route::delete('/{id}', [ConnectionController::class, 'destroy']);
});
use App\Http\Controllers\ResumeVersionController;

Route::middleware('auth:sanctum')->prefix('resume-versions')->group(function () {
    Route::get('/', [ResumeVersionController::class, 'index']);
    Route::post('/', [ResumeVersionController::class, 'store']);
    Route::get('/{id}', [ResumeVersionController::class, 'show']);
    Route::put('/{id}', [ResumeVersionController::class, 'update']);
    Route::post('/{id}/default', [ResumeVersionController::class, 'makeDefault']);
    Route::post('/{id}/share', [ResumeVersionController::class, 'share']);      // naya
    Route::delete('/{id}/share', [ResumeVersionController::class, 'unshare']); 
    Route::delete('/{id}', [ResumeVersionController::class, 'destroy']);
});
// public, bina login ke (group ke bahar)
Route::get('/shared-resume/{token}', [ResumeVersionController::class, 'showShared']);