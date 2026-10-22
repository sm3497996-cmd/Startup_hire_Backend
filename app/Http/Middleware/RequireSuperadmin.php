<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class RequireSuperadmin
{
    const ME_URL = 'http://localhost:8080/startup-hire-api/routes/me.php';

    public function handle(Request $request, Closure $next)
    {
        $sid = $request->cookies->get('PHPSESSID');

        if (!$sid) {
            return response()->json(['success' => false, 'message' => 'You must be logged in.'], 401);
        }

        try {
            $res = Http::timeout(5)
                ->acceptJson()
                ->withHeaders(['Cookie' => 'PHPSESSID=' . $sid])
                ->get(self::ME_URL);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Auth service unreachable.'], 503);
        }

        if (!$res->ok() || $res->json('user.role') !== 'superadmin') {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}