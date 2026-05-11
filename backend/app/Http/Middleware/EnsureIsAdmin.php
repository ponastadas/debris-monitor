<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsAdmin
{
    /**
     * Reject inactive admin accounts.
     *
     * Note: authentication (valid token, correct tokenable_type) is already
     * enforced by the `auth:admin` middleware that runs before this one.
     * This layer only adds the is_active check.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $admin = auth('admin')->user();

        if (! $admin?->isActive()) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'error'   => [
                    'code'    => 'ACCOUNT_INACTIVE',
                    'message' => 'This admin account has been deactivated.',
                    'details' => [],
                ],
            ], 403);
        }

        return $next($request);
    }
}
