<?php

namespace App\Http\Controllers;

use App\Http\Requests\PushTokenRequest;
use App\Models\DevicePushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushTokenController extends Controller
{
    /**
     * Registers (or re-registers) this device. The token is unique per device, so if another
     * account signed in on the same phone earlier, the row moves to the current user.
     */
    public function store(PushTokenRequest $request): JsonResponse
    {
        $row = DevicePushToken::updateOrCreate(
            ['token' => $request->validated('token')],
            [
                'user_id' => $request->user()->id,
                'platform' => $request->validated('platform'),
                'last_used_at' => now(),
            ],
        );

        return response()->json($row, $row->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->pushTokens()->where('token', (string) $request->input('token'))->delete();

        return response()->json(['message' => 'Push token removed']);
    }
}
