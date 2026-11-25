<?php

namespace App\Http\Controllers;

use App\Enums\InquiryStatus;
use App\Http\Resources\ChatResource;
use App\Models\Chat;
use App\Models\Inquiry;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chat endpoints used by the inquiry / moderation screens (`/api/support-chat`). Admins may read
 * any conversation to review flagged messages.
 */
class SupportChatController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'studentId' => ['required', 'string', 'exists:users,id'],
            'ownerId' => ['required', 'string', 'exists:users,id'],
            'inquiryId' => ['nullable', 'string', 'exists:inquiries,id'],
        ], ['studentId.*' => 'Student and owner ids are required', 'ownerId.*' => 'Student and owner ids are required']);

        $me = $request->user();

        if (! in_array($me->id, [$data['studentId'], $data['ownerId']], true) && ! $me->isAdmin()) {
            return response()->json(['message' => 'Not authorized to create this chat'], 403);
        }

        $existing = Chat::between($data['studentId'], $data['ownerId'])->first();
        $chat = $existing ?? Chat::openBetween($data['studentId'], $data['ownerId'], seenBy: [$me->id]);

        if (! empty($data['inquiryId'])) {
            Inquiry::whereKey($data['inquiryId'])->update(['chat_id' => $chat->id, 'status' => InquiryStatus::Accepted]);
        }

        return response()->json(new ChatResource($chat->load('users:id,username,avatar')), $existing ? 200 : 201);
    }

    public function send(Request $request, ChatService $chats): JsonResponse
    {
        $data = $request->validate([
            'chatId' => ['required', 'string'],
            'text' => ['required', 'string', 'max:5000'],
        ], ['*' => 'Chat id and message text are required']);

        $chat = Chat::forUser($request->user()->id)->find($data['chatId']);

        if (! $chat) {
            return response()->json(['message' => 'Chat not found'], 404);
        }

        return response()->json($chats->send($chat, $request->user(), trim($data['text'])), 201);
    }

    public function messages(Request $request, string $chatId): JsonResponse
    {
        $chat = Chat::query()
            ->when(! $request->user()->isAdmin(), fn ($query) => $query->forUser($request->user()->id))
            ->find($chatId);

        if (! $chat) {
            return response()->json(['message' => 'Chat not found'], 404);
        }

        return response()->json($chat->messages()->oldest()->oldest('id')->get());
    }
}
