<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConnectionController extends Controller
{
    private function meId(Request $r): int
    {
        return (int) $r->user()->id;
    }

    // Row between two users, in either direction
    private function between(int $a, int $b)
    {
        return DB::table('connections')
            ->where(fn($q) => $q->where('requester_id', $a)->where('receiver_id', $b))
            ->orWhere(fn($q) => $q->where('requester_id', $b)->where('receiver_id', $a))
            ->first();
    }

    private function people(array $ids)
    {
        if (!$ids) return collect();
        return DB::table('users')->whereIn('id', $ids)->get(['id', 'name', 'role'])->keyBy('id');
    }

    private function shape($u): array
    {
        return ['id' => (int) $u->id, 'name' => $u->name, 'role' => $u->role];
    }

    // People I can connect with (candidates + employers)
    public function suggestions(Request $r)
    {
        $me = $this->meId($r);

        $exclude = DB::table('connections')
            ->whereIn('status', ['pending', 'accepted'])
            ->where(fn($q) => $q->where('requester_id', $me)->orWhere('receiver_id', $me))
            ->get(['requester_id', 'receiver_id'])
            ->flatMap(fn($c) => [$c->requester_id, $c->receiver_id])
            ->push($me)->unique()->values()->all();

        $q = DB::table('users')
            ->whereNotIn('id', $exclude)
            ->whereIn('role', ['candidate', 'employer']);

        if ($r->filled('q')) {
            $q->where('name', 'like', '%' . $r->q . '%');
        }
        if (in_array($r->role, ['candidate', 'employer'])) {
            $q->where('role', $r->role);
        }

        $list = $q->orderBy('name')->limit(40)->get(['id', 'name', 'role'])
            ->map(fn($u) => $this->shape($u));

        return response()->json(['success' => true, 'people' => $list]);
    }

    // Send request (works for any role combination)
    public function send(Request $r)
    {
        $me = $this->meId($r);
        $data = $r->validate(['user_id' => 'required|integer']);
        $otherId = (int) $data['user_id'];

        if ($otherId === $me) {
            return response()->json(['success' => false, 'message' => 'You cannot connect with yourself.'], 422);
        }

        $other = DB::table('users')->where('id', $otherId)->first(['id', 'role']);
        if (!$other) {
            return response()->json(['success' => false, 'message' => 'User not found.'], 404);
        }

        $exists = $this->between($me, $otherId);
        if ($exists) {
            if ($exists->status === 'rejected') {
                DB::table('connections')->where('id', $exists->id)->delete(); // allow trying again
            } else {
                return response()->json(['success' => false, 'message' => 'A request or connection already exists.'], 409);
            }
        }

        DB::table('connections')->insert([
            'requester_id' => $me,
            'requester_type' => $r->user()->role ?? 'candidate',
            'receiver_id' => $otherId,
            'receiver_type' => $other->role ?? 'candidate',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Connection request sent.']);
    }

    // Accept / reject a request sent to me
    public function respond(Request $r, $id)
    {
        $me = $this->meId($r);
        $r->validate(['action' => 'required|in:accept,reject']);

        $conn = DB::table('connections')
            ->where('id', $id)->where('receiver_id', $me)->where('status', 'pending')->first();

        if (!$conn) {
            return response()->json(['success' => false, 'message' => 'Request not found.'], 404);
        }

        DB::table('connections')->where('id', $id)->update([
            'status' => $r->action === 'accept' ? 'accepted' : 'rejected',
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    // My accepted connections
    public function index(Request $r)
    {
        $me = $this->meId($r);

        $rows = DB::table('connections')->where('status', 'accepted')
            ->where(fn($q) => $q->where('requester_id', $me)->orWhere('receiver_id', $me))
            ->orderByDesc('updated_at')->get();

        $otherIds = $rows->map(fn($c) => $c->requester_id == $me ? $c->receiver_id : $c->requester_id)->all();
        $users = $this->people($otherIds);

        $list = $rows->map(function ($c) use ($me, $users) {
            $otherId = $c->requester_id == $me ? $c->receiver_id : $c->requester_id;
            $u = $users->get($otherId);
            if (!$u) return null;
            return $this->shape($u) + ['connection_id' => $c->id, 'since' => $c->updated_at];
        })->filter()->values();

        return response()->json(['success' => true, 'connections' => $list]);
    }

    // Requests I received
    public function pending(Request $r)
    {
        $me = $this->meId($r);
        $rows = DB::table('connections')->where('receiver_id', $me)->where('status', 'pending')
            ->orderByDesc('created_at')->get();

        $users = $this->people($rows->pluck('requester_id')->all());

        $list = $rows->map(function ($c) use ($users) {
            $u = $users->get($c->requester_id);
            if (!$u) return null;
            return $this->shape($u) + ['connection_id' => $c->id, 'sent_at' => $c->created_at];
        })->filter()->values();

        return response()->json(['success' => true, 'requests' => $list]);
    }

    // Requests I sent (still waiting)
    public function sent(Request $r)
    {
        $me = $this->meId($r);
        $rows = DB::table('connections')->where('requester_id', $me)->where('status', 'pending')
            ->orderByDesc('created_at')->get();

        $users = $this->people($rows->pluck('receiver_id')->all());

        $list = $rows->map(function ($c) use ($users) {
            $u = $users->get($c->receiver_id);
            if (!$u) return null;
            return $this->shape($u) + ['connection_id' => $c->id, 'sent_at' => $c->created_at];
        })->filter()->values();

        return response()->json(['success' => true, 'sent' => $list]);
    }

    // Remove a connection or withdraw a sent request
    public function destroy(Request $r, $id)
    {
        $me = $this->meId($r);
        $deleted = DB::table('connections')->where('id', $id)
            ->where(fn($q) => $q->where('requester_id', $me)->orWhere('receiver_id', $me))
            ->delete();

        return response()->json(['success' => (bool) $deleted]);
    }
}