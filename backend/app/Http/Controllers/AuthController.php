<?php

namespace App\Http\Controllers;

use App\Enums\NotificationType;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\TokenCookie;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, NotificationService $notifications): JsonResponse
    {
        $data = $request->validated();

        $existing = User::where('username', $data['username'])->orWhere('email', $data['email'])->first();

        if ($existing) {
            return $this->conflict($existing->username === $data['username']);
        }

        try {
            $user = User::create([
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => $data['role'],
                'gender' => $data['gender'],
                'full_name' => $data['fullName'],
                'phone' => $data['phone'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with another sign-up for the same username/email.
            return $this->conflict(User::where('username', $data['username'])->exists());
        }

        $notifications->create(
            userId: $user->id,
            type: NotificationType::AccountCreated,
            title: 'Welcome to BoardingFinder',
            message: "Hi {$data['fullName']}, your account is ready. You can now explore boardings, track alerts, and message owners safely.",
            metadata: ['role' => $data['role'], 'username' => $data['username']],
            bypassPreference: true,
        );

        return response()->json(['message' => 'User created successfully'], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('username', $request->validated('username'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json(['message' => 'Invalid Credentials!'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Your account has been suspended. Please contact support.'], 403);
        }

        $token = $user->createToken('web', ['*'], now()->addDays(TokenCookie::LIFETIME_DAYS));

        // The client keeps this object in localStorage as the "current user".
        return response()->json($user)->withCookie(TokenCookie::make($token->plainTextToken));
    }

    public function logout(Request $request): JsonResponse
    {
        // Revoke the token server-side too, not just the cookie. Logging out while already
        // logged out is fine, so this route does not require authentication.
        Auth::guard('token')->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logout Successful'])->withCookie(TokenCookie::forget());
    }

    private function conflict(bool $usernameTaken): JsonResponse
    {
        return response()->json([
            'message' => $usernameTaken ? 'That username is already taken.' : 'That email is already registered.',
        ], 409);
    }
}
