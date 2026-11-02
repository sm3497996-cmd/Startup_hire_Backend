<?php

namespace App\Http\Controllers;

use App\Models\CandidateAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AttachmentController extends Controller
{
    private function candidateId(Request $request): int
    {
        $legacy = $request->attributes->get('legacy_user');
        if (strtolower($legacy['role'] ?? '') !== 'candidate') {
            return 0;
        }
        return (int) ($legacy['id'] ?? 0);
    }

    public function store(Request $request)
    {
        $candidateId = $this->candidateId($request);
        if (!$candidateId) {
            return response()->json(['success' => false, 'message' => 'Only candidates can upload files.'], 403);
        }

        $v = Validator::make($request->all(), [
            'section' => 'required|in:course,experience,project,achievement',
            'file'    => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }

        if (CandidateAttachment::where('candidate_id', $candidateId)->count() >= 30) {
            return response()->json(['success' => false, 'message' => 'File limit reached (30).'], 422);
        }

        $file = $request->file('file');
        $name = mb_substr($file->getClientOriginalName(), 0, 255);
        $mime = $file->getMimeType();
        $size = $file->getSize();

        $path = $file->store("candidate_attachments/{$candidateId}", 'public');

        $att = CandidateAttachment::create([
            'candidate_id'  => $candidateId,
            'section'       => $request->input('section'),
            'original_name' => $name,
            'file_path'     => $path,
            'mime_type'     => $mime,
            'file_size'     => $size,
        ]);

        return response()->json(['success' => true, 'attachment' => $att->toApi()]);
    }

    public function destroy(Request $request, $id)
    {
        $candidateId = $this->candidateId($request);
        if (!$candidateId) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $att = CandidateAttachment::where('id', $id)->where('candidate_id', $candidateId)->first();
        if (!$att) {
            return response()->json(['success' => false, 'message' => 'File not found.'], 404);
        }

        Storage::disk('public')->delete($att->file_path);
        $att->delete();

        return response()->json(['success' => true]);
    }
}