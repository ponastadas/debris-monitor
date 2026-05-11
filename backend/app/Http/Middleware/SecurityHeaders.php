<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attach baseline security headers to every HTTP response.
 *
 * CSP is intentionally omitted here — it requires a careful policy specific to
 * the frontend's CDN/font sources and will be added in a follow-up pass.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Prevent the page from being embedded in a frame — mitigates clickjacking.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Prevent MIME-type sniffing — forces browsers to honour Content-Type.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Limit referrer information sent to other origins.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
