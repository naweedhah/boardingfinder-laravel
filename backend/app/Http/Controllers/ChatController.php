<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChatResource;
use App\Models\Chat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    private const PARTICIPANTS = 'users:id,username,avatar';

    public function index(Request $request): JsonResponse
    {
        $me = $request->user()->id;

        $chats = Chat::forUser($me)->with(self::PARTICIPANTS)->latest('updated_at')->latest('id')->get();

        return response()->json($chats->map(fn (Chat $chat) => (new ChatResource($chat))->forViewer($me)));
    }

    /** Opens a conversation and marks it as read by the viewer. */
    public function show(Request $request, string $id): JsonResponse
    {
        $me = $request->user()->id;

        $chat = Chat::forUser($me)->findOrFail($id);
        $chat->users()->updateExistingPivot($me, ['seen' => true]);

        $chat->load([
            'users:id,username,avatar',
            'messages' => fn ($query) => $query->oldest()->oldest('id'),
        ]);

        return response()->json(new ChatResource($chat));
    }

    /** Returns the existing chat with that user if there is one. */
    public function store(Request $request): JsonResponse
    {
        $me = $request->user()->id;

        $receiverId = $request->validate([
            'receiverId' => ['required', 'string', 'exists:users,id', Rule::notIn([$me])],
        ], [
            'receiverId.not_in' => 'You cannot start a chat with yourself.',
        ])['receiverId'];

        $chat = Chat::openBetween($me, $receiverId, seenBy: [$me])->load(self::PARTICIPANTS);

        return response()->json(new ChatResource($chat));
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $me = $request->user()->id;

        $chat = Chat::forUser($me)->findOrFail($id);
        $chat->markSeenOnlyBy($me);

        return response()->json(new ChatResource($chat->load(self::PARTICIPANTS)));
    }
}
