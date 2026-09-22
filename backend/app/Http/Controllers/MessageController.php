<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function store(Request $request, string $chatId, ChatService $chats): JsonResponse
    {
        $text = $request->validate(['text' => ['required', 'string', 'max:5000']], [
            'text.required' => 'Message text is required',
        ])['text'];

        $chat = Chat::forUser($request->user()->id)->findOrFail($chatId);

        return response()->json($chats->send($chat, $request->user(), trim($text)));
    }
}
