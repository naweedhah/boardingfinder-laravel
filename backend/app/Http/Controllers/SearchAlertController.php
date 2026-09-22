<?php

namespace App\Http\Controllers;

use App\Enums\NotificationType;
use App\Http\Requests\SearchAlertRequest;
use App\Models\Watchlist;
use App\Services\NotificationService;
use App\Support\Keys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saved search alerts: watchlist rows that are not tied to one particular listing.
 */
class SearchAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            Watchlist::where('user_id', $request->user()->id)->whereNull('post_id')->latest()->get(),
        );
    }

    public function store(SearchAlertRequest $request, NotificationService $notifications): JsonResponse
    {
        $alert = Watchlist::create([
            ...Keys::snake($request->validated()),
            'user_id' => $request->user()->id,
        ])->fresh();

        $where = $alert->area ?: $alert->city;

        $notifications->create(
            userId: $request->user()->id,
            type: NotificationType::SearchMatch,
            title: 'Search alert saved',
            message: "We'll notify you about matching boardings in {$where}.",
            metadata: ['watchlistId' => $alert->id, 'city' => $alert->city, 'area' => $alert->area],
        );

        return response()->json($alert, 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $alert = Watchlist::where('user_id', $request->user()->id)->whereNull('post_id')->find($id);

        if (! $alert) {
            return response()->json(['message' => 'Saved alert not found.'], 404);
        }

        $alert->delete();

        return response()->json(['message' => 'Saved alert deleted.']);
    }
}
