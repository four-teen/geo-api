<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && (bool) $user->must_change_password) {
            return response()->json([
                'success' => false,
                'message' => 'Password change required before continuing.',
                'data' => [
                    'must_change_password' => true,
                ],
            ], 403);
        }

        return $next($request);
    }
}
