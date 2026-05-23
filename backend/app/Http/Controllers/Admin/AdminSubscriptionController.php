<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $subscriptions = Subscription::with('user')
            ->when($request->plan, fn ($q, $p) => $q->where('plan', $p))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->through(fn (Subscription $s) => [
                'id' => $s->id,
                'user_id' => $s->user_id,
                'user_name' => $s->user?->name,
                'user_email' => $s->user?->email,
                'plan' => $s->plan,
                'status' => $s->status,
                'current_period_start' => $s->current_period_start?->toIso8601String(),
                'current_period_end' => $s->current_period_end?->toIso8601String(),
                'canceled_at' => $s->canceled_at?->toIso8601String(),
                'created_at' => $s->created_at->toIso8601String(),
            ]);

        return $this->success($subscriptions);
    }
}
