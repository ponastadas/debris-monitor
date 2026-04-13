<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAccount;
use App\Models\AdminAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = AdminAccount::where('email', $request->email)->first();

        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (! $admin->isActive()) {
            return $this->error('ACCOUNT_INACTIVE', 'This admin account has been deactivated.', 403);
        }

        // Revoke previous admin-session tokens so only one session is active at a time
        $admin->tokens()->where('name', 'admin-session')->delete();

        $token = $admin->createToken('admin-session')->plainTextToken;

        $admin->update(['last_login_at' => now()]);

        AdminAuditLog::record($admin->id, 'login');

        return $this->success([
            'admin' => $this->adminResource($admin),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var AdminAccount $admin */
        $admin = auth('admin')->user();

        AdminAuditLog::record($admin->id, 'logout');

        $request->user('admin')->currentAccessToken()->delete();

        return $this->success(null);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success($this->adminResource($request->user('admin')));
    }

    private function adminResource(AdminAccount $admin): array
    {
        return [
            'id'            => $admin->id,
            'name'          => $admin->name,
            'email'         => $admin->email,
            'is_active'     => $admin->is_active,
            'last_login_at' => $admin->last_login_at?->toIso8601String(),
        ];
    }
}
