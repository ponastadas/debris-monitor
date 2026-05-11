<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminApiKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $keys = ApiKey::with('user')
            ->when($request->user_id, fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->tier, fn ($q, $t) => $q->where('tier', $t))
            ->withCount(['usages as usage_today' => fn ($q) => $q->whereDate('created_at', today())])
            ->orderByDesc('last_used_at')
            ->paginate(50)
            ->through(fn (ApiKey $k) => [
                'id'            => $k->id,
                'user_id'       => $k->user_id,
                'user_email'    => $k->user?->email,
                'user_name'     => $k->user?->name,
                'name'          => $k->name,
                'key_prefix'    => substr($k->key, 0, 12).'...',
                'tier'          => $k->tier,
                'daily_limit'   => $k->daily_limit,
                'usage_today'   => $k->usage_today ?? 0,
                'last_used_at'  => $k->last_used_at?->toIso8601String(),
                'created_at'    => $k->created_at->toIso8601String(),
            ]);

        return $this->success($keys);
    }
}
