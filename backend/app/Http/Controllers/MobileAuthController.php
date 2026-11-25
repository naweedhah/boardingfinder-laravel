<?php

namespace App\Http\Controllers;

use App\Http\Requests\MobileLoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Token login for the mobile app. The web client gets its token in an httpOnly cookie; a
 * native app stores it in the platform keystore (iOS Keychain / Android Keystore via
 * expo-secure-store) and sends it as a bearer token, so here it is returned in the body.
 */
class MobileAuthController extends Controller
{
    public const TOKEN_LIFETIME_DAYS = 30;

    public function login(MobileLoginRequest $request): JsonResponse
    {
        $user = User::where('username', $request->validated('username'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json(['message' => 'Invalid Credentials!'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Your account has been suspended. Please contact support.'], 403);
        }

        $expiresAt = now()->addDays(self::TOKEN_LIFETIME_DAYS);
        $token = $user->createToken('mobile:'.$request->validated('deviceName'), ['*'], $expiresAt);

        return response()->json([
            'token' => $token->plainTextToken,
            'expiresAt' => $expiresAt->toIso8601String(),
            'user' => $user,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    /**
     * Revokes this device's token and forgets its push token, so a signed-out phone stops
     * receiving the previous user's notifications.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($pushToken = $request->string('pushToken')->toString()) {
            $user->pushTokens()->where('token', $pushToken)->delete();
        }

        $user->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logout Successful']);
    }
}
