<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->when(
                $request->search,
                fn ($q, $s) => $q->where('email', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")
            )
            ->when($request->role, fn ($q, $r) => $q->where('role', $r))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->with(['subscription'])
            ->withCount('apiKeys')
            ->orderByDesc('created_at')
            ->paginate(25)
            ->through(fn (User $u) => $this->userResource($u));

        return $this->success($users);
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['subscription', 'apiKeys']);

        return $this->success(array_merge($this->userResource($user), [
            'api_keys_count' => $user->apiKeys->count(),
            'api_keys'       => $user->apiKeys->map(fn ($k) => [
                'id'         => $k->id,
                'name'       => $k->name,
                'tier'       => $k->tier,
                'last_used'  => $k->last_used_at?->toIso8601String(),
                'created_at' => $k->created_at->toIso8601String(),
            ]),
        ]));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        // Prevent self-suspension or self-demotion
        if ($request->user()->id === $user->id) {
            if (($request->status ?? $user->status) === 'suspended') {
                return $this->error('SELF_ACTION', 'You cannot suspend your own account.', 422);
            }
            if (($request->role ?? $user->role) !== 'admin') {
                return $this->error('SELF_ACTION', 'You cannot remove your own admin role.', 422);
            }
        }

        $data = $request->only(['name', 'role', 'status']);

        if (isset($data['status']) && $data['status'] === 'suspended' && $user->status !== 'suspended') {
            $data['suspended_at'] = now();
        } elseif (isset($data['status']) && $data['status'] === 'active') {
            $data['suspended_at'] = null;
        }

        $user->update($data);

        return $this->success($this->userResource($user->fresh(['subscription'])));
    }

    public function impersonate(Request $request, User $user): JsonResponse
    {
        // Block impersonating another admin
        if ($user->isAdmin() && $request->user()->id !== $user->id) {
            return $this->error('FORBIDDEN', 'Cannot impersonate another admin.', 403);
        }

        $token = $user->createToken('impersonation')->plainTextToken;

        Log::info('Admin impersonation', [
            'admin_id'  => $request->user()->id,
            'admin_email' => $request->user()->email,
            'target_id' => $user->id,
            'target_email' => $user->email,
        ]);

        return $this->success([
            'token' => $token,
            'user'  => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    private function userResource(User $user): array
    {
        return [
            'id'                => $user->id,
            'name'              => $user->name,
            'email'             => $user->email,
            'role'              => $user->role ?? 'user',
            'status'            => $user->status ?? 'active',
            'subscription_plan' => $user->currentPlan(),
            'subscription_status' => $user->subscription?->status ?? 'none',
            'api_keys_count'    => $user->api_keys_count ?? null,
            'suspended_at'      => $user->suspended_at?->toIso8601String(),
            'created_at'        => $user->created_at->toIso8601String(),
        ];
    }
}
