<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function store(StoreUserRequest $request): JsonResponse
    {
        $admin = auth('admin')->user();

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => $request->password, // hashed by the cast on User
            'status'   => $request->input('status', 'active'),
        ]);

        AdminAuditLog::record(
            $admin->id,
            AdminAuditLog::USER_CREATED,
            'User',
            $user->id,
            ['email' => $user->email, 'name' => $user->name, 'status' => $user->status],
        );

        return $this->success($this->userResource($user), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->when(
                $request->search,
                fn ($q, $s) => $q->where('email', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")
            )
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
        $admin = auth('admin')->user();
        $data  = $request->only(['name', 'status']);

        if (isset($data['status']) && $data['status'] === 'suspended' && $user->status !== 'suspended') {
            $data['suspended_at'] = now();
        } elseif (isset($data['status']) && $data['status'] === 'active') {
            $data['suspended_at'] = null;
        }

        $user->update($data);

        // Log a targeted status event when status changed; otherwise log a
        // generic user.updated for field-level changes (e.g. name only).
        if (isset($data['status'])) {
            $action = $data['status'] === 'suspended'
                ? AdminAuditLog::USER_SUSPENDED
                : AdminAuditLog::USER_ACTIVATED;

            AdminAuditLog::record(
                $admin->id,
                $action,
                'User',
                $user->id,
                ['email' => $user->email],
            );
        } else {
            AdminAuditLog::record(
                $admin->id,
                AdminAuditLog::USER_UPDATED,
                'User',
                $user->id,
                ['email' => $user->email, 'fields' => array_keys($data)],
            );
        }

        return $this->success($this->userResource($user->fresh(['subscription'])));
    }

    public function impersonate(Request $request, User $user): JsonResponse
    {
        $admin = auth('admin')->user();

        // Impersonation tokens expire in 1 hour. They are delivered in the JSON
        // response body only — never via a URL parameter.
        $token = $user->createToken('impersonation', ['*'], now()->addHour())->plainTextToken;

        AdminAuditLog::record(
            $admin->id,
            AdminAuditLog::IMPERSONATION_STARTED,
            'User',
            $user->id,
            ['target_email' => $user->email],
        );

        return $this->success([
            'token' => $token,
            'user'  => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    private function userResource(User $user): array
    {
        return [
            'id'                  => $user->id,
            'name'                => $user->name,
            'email'               => $user->email,
            'status'              => $user->status ?? 'active',
            'subscription_plan'   => $user->currentPlan(),
            'subscription_status' => $user->subscription?->status ?? 'none',
            'api_keys_count'      => $user->api_keys_count ?? null,
            'suspended_at'        => $user->suspended_at?->toIso8601String(),
            'created_at'          => $user->created_at->toIso8601String(),
        ];
    }
}
