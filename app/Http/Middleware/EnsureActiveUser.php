<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && isset($user->is_active) && !$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Account is inactive.',
            ], 403);
        }

        return $next($request);
    }
}
