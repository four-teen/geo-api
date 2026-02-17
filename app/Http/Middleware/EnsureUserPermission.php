<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserPermission
{
    public function handle(Request $request, Closure $next, string ...$permissionCodes)
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

        if (!method_exists($user, 'hasPermissionCode')) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden.',
            ], 403);
        }

        $normalized = [];
        foreach ($permissionCodes as $entry) {
            foreach (explode(',', (string) $entry) as $code) {
                $code = trim($code);
                if ($code !== '') {
                    $normalized[$code] = true;
                }
            }
        }

        $codes = array_keys($normalized);

        foreach ($codes as $code) {
            if ($user->hasPermissionCode($code)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'You do not have permission to access this transaction.',
        ], 403);
    }
}
