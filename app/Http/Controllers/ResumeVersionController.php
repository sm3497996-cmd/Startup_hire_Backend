<?php

namespace App\Http\Controllers;

use App\Models\ResumeVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ResumeVersionController extends Controller
{
    private function candidateId(Request $request): int
    {
        return (int) $request->user()->id;
    }

    private function decode($data)
    {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            return $decoded === null ? [] : $decoded;
        }
        return $data ?? [];
    }

    private function encode($data): string
    {
        return is_string($data) ? $data : json_encode($data);
    }

    private function format(ResumeVersion $v, bool $withData = true): array
    {
        $row = [
            'id'           => $v->id,
            'title'        => $v->title,
            'target_field' => $v->target_field,
            'is_default'   => (bool) $v->is_default,
            'share_token'  => $v->share_token,
            'shared_at'    => $v->shared_at,
            'created_at'   => $v->created_at,
            'updated_at'   => $v->updated_at,
        ];
        if ($withData) {
            $row['data'] = $this->decode($v->data);
        }
        return $row;
    }

    private function findOwn(Request $request, $id): ResumeVersion
    {
        return ResumeVersion::where('id', $id)
            ->where('candidate_id', $this->candidateId($request))
            ->firstOrFail();
    }

    public function index(Request $request)
    {
        $versions = ResumeVersion::where('candidate_id', $this->candidateId($request))
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($v) => $this->format($v, false));

        return response()->json(['success' => true, 'versions' => $versions]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title'        => 'required|string|max:80',
            'target_field' => 'nullable|string|max:60',
            'data'         => 'required',
        ]);

        $candidateId = $this->candidateId($request);
        $isFirst = ResumeVersion::where('candidate_id', $candidateId)->count() === 0;

        $version = ResumeVersion::create([
            'candidate_id' => $candidateId,
            'title'        => $request->input('title'),
            'target_field' => $request->input('target_field'),
            'data'         => $this->encode($request->input('data')),
            'is_default'   => $isFirst,
        ]);

        return response()->json(['success' => true, 'version' => $this->format($version)], 201);
    }

    public function show(Request $request, $id)
    {
        $version = $this->findOwn($request, $id);
        return response()->json(['success' => true, 'version' => $this->format($version)]);
    }

    public function update(Request $request, $id)
    {
        $version = $this->findOwn($request, $id);

        $request->validate([
            'title'        => 'sometimes|required|string|max:80',
            'target_field' => 'nullable|string|max:60',
        ]);

        if ($request->has('title')) {
            $version->title = $request->input('title');
        }
        if ($request->has('target_field')) {
            $version->target_field = $request->input('target_field');
        }
        if ($request->has('data')) {
            $version->data = $this->encode($request->input('data'));
        }
        $version->save();

        return response()->json(['success' => true, 'version' => $this->format($version)]);
    }

    public function makeDefault(Request $request, $id)
    {
        $version = $this->findOwn($request, $id);

        ResumeVersion::where('candidate_id', $version->candidate_id)->update(['is_default' => false]);
        $version->is_default = true;
        $version->save();

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $version = $this->findOwn($request, $id);
        $wasDefault = (bool) $version->is_default;
        $candidateId = $version->candidate_id;
        $version->delete();

        // default delete hua to koi aur version default bana do
        if ($wasDefault) {
            $next = ResumeVersion::where('candidate_id', $candidateId)->orderByDesc('id')->first();
            if ($next) {
                $next->update(['is_default' => true]);
            }
        }

        return response()->json(['success' => true]);
    }

    // ---------- Share ----------

    public function share(Request $request, $id)
    {
        $version = $this->findOwn($request, $id);

        if (!$version->share_token) {
            $version->share_token = Str::random(40);
            $version->shared_at = now();
            $version->save();
        }

        return response()->json(['success' => true, 'share_token' => $version->share_token]);
    }

    public function unshare(Request $request, $id)
    {
        $version = $this->findOwn($request, $id);
        $version->update(['share_token' => null, 'shared_at' => null]);

        return response()->json(['success' => true]);
    }

    // public, bina login ke
    public function showShared($token)
    {
        $version = ResumeVersion::where('share_token', $token)->firstOrFail();
        $data = $this->stripPrivate($this->decode($version->data));

        return response()->json([
            'success' => true,
            'title'   => $version->title,
            'field'   => $version->target_field,
            'resume'  => $data,
        ]);
    }

    // public link me ye keys kisi bhi nesting me ho, hata do
    private function stripPrivate(array $data): array
    {
        $hide = [
    'mother', 'father', 'mother_name', 'motherName', 'father_name', 'fatherName',
    'dob', 'whatsapp', 'attachments',
];

        foreach ($data as $key => $value) {
            if (in_array($key, $hide, true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->stripPrivate($value);
            }
        }

        return $data;
    }
}