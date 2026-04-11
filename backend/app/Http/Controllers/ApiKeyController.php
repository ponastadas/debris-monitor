<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $keys = $request->user()
            ->apiKeys()
            ->get()
            ->map(fn ($key) => [
                'id'               => $key->id,
                'name'             => $key->name,
                'tier'             => $key->tier,
                'daily_limit'      => $key->daily_limit,
                'usage_today'      => $key->todayUsageCount(),
                'webhooks_enabled' => $key->webhooks_enabled,
                'last_used_at'     => $key->last_used_at?->toIso8601String(),
                'created_at'       => $key->created_at->toIso8601String(),
            ]);

        return response()->json($keys);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['name' => 'required|string|max:100']);

        $defaults = ApiKey::tierDefaults('free');

        $key = $request->user()->apiKeys()->create([
            'name' => $request->input('name'),
            'key'  => ApiKey::generate(),
            'tier' => 'free',
            ...$defaults,
        ]);

        return response()->json([
            'id'          => $key->id,
            'name'        => $key->name,
            'key'         => $key->key,  // shown once only
            'tier'        => $key->tier,
            'daily_limit' => $key->daily_limit,
            'created_at'  => $key->created_at->toIso8601String(),
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $key = $request->user()->apiKeys()->findOrFail($id);
        $key->delete();

        return response()->json(['message' => 'API key revoked']);
    }
}
