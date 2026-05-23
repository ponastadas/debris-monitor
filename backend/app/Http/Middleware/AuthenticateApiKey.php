<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\ApiUsage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawKey = $request->header('X-API-Key') ?? $request->query('api_key');

        if (! $rawKey) {
            return response()->json(['error' => 'API key required'], 401);
        }

        $apiKey = ApiKey::where('key', $rawKey)->whereNull('deleted_at')->first();

        if (! $apiKey) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }

        if ($apiKey->expires_at && $apiKey->expires_at->isPast()) {
            return response()->json(['error' => 'API key expired'], 401);
        }

        $used = $apiKey->todayUsageCount();

        if ($apiKey->daily_limit !== null && $used >= $apiKey->daily_limit) {
            return response()->json([
                'error' => 'Daily rate limit exceeded',
                'upgrade_url' => url('/billing'),
            ], 429);
        }

        $apiKey->update(['last_used_at' => now()]);
        $request->attributes->set('api_key', $apiKey);

        $start = microtime(true);
        $response = $next($request);
        $ms = (int) round((microtime(true) - $start) * 1000);

        ApiUsage::create([
            'api_key_id' => $apiKey->id,
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'status_code' => $response->getStatusCode(),
            'response_ms' => $ms,
            'ip' => $request->ip(),
        ]);

        $remaining = $apiKey->daily_limit !== null
            ? max(0, $apiKey->daily_limit - $used - 1)
            : PHP_INT_MAX;

        $response->headers->set('X-RateLimit-Limit', (string) ($apiKey->daily_limit ?? 'unlimited'));
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
        $response->headers->set('X-RateLimit-Reset', (string) now()->endOfDay()->timestamp);
        $response->headers->set('X-API-Tier', $apiKey->tier);

        return $response;
    }
}
