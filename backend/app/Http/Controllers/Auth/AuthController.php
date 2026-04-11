<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        $token = $user->createToken('spa')->plainTextToken;

        return $this->success([
            'user'  => $this->userResource($user),
            'token' => $token,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->isSuspended()) {
            Auth::logout();
            return $this->error('USER_SUSPENDED', 'Your account has been suspended. Please contact support.', 403);
        }

        // Revoke previous SPA tokens to keep one active session per device
        $user->tokens()->where('name', 'spa')->delete();

        $token = $user->createToken('spa')->plainTextToken;

        return $this->success([
            'user'  => $this->userResource($user),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('subscription');

        return $this->success($this->userResource($user));
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        // Always return 200 to prevent user enumeration
        Password::sendResetLink(['email' => $request->email]);

        return $this->success(['message' => 'If that email is registered, a reset link has been sent.']);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete(); // invalidate all sessions on password reset
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return $this->success(['message' => 'Password has been reset successfully.']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $request->validate([
            'name'  => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'string', 'email:rfc', 'max:255', 'unique:users,email,'.$request->user()->id],
        ]);

        $request->user()->update($request->only(['name', 'email']));

        return $this->success($this->userResource($request->user()->fresh()));
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password'      => ['required', 'string', 'current_password'],
            'password'              => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $request->user()->update(['password' => Hash::make($request->password)]);

        // Revoke all other tokens so other sessions are invalidated
        $request->user()->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        return $this->success(['message' => 'Password updated successfully.']);
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
            'created_at'        => $user->created_at?->toIso8601String(),
        ];
    }
}
