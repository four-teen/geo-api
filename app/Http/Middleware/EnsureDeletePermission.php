<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureDeletePermission
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (method_exists($user, 'isAdministrator') && $user->isAdministrator()) {
            return $next($request);
        }

        if ((bool) ($user->can_delete ?? false)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Delete access is disabled for this account.',
        ], 403);
    }
}
