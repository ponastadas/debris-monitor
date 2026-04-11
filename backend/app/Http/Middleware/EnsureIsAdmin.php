<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'error'   => [
                    'code'    => 'FORBIDDEN',
                    'message' => 'Admin access required.',
                    'details' => [],
                ],
            ], 403);
        }

        return $next($request);
    }
}
