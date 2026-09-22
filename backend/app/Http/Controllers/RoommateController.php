<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoommateProfileRequest;
use App\Models\RoommateMatch;
use App\Models\RoommatePreference;
use App\Services\AlertNotifier;
use App\Services\RoommateMatcher;
use App\Support\Keys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoommateController extends Controller
{
    public function __construct(private RoommateMatcher $matcher) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(RoommatePreference::where('user_id', $request->user()->id)->first());
    }

    /** Saves the profile, then refreshes the stored top matches and notifies strong ones. */
    public function upsert(RoommateProfileRequest $request, AlertNotifier $alerts): JsonResponse
    {
        $user = $request->user();

        $profile = RoommatePreference::updateOrCreate(
            ['user_id' => $user->id],
            Keys::snake($request->validated()),
        );

        $matches = $this->matcher->matchesFor($user, $profile);

        foreach ($matches->take(5) as $match) {
            // Pair rows are stored once, with the lower id first.
            $ids = [$user->id, $match['user']['id']];
            sort($ids, SORT_STRING);

            RoommateMatch::updateOrCreate(
                ['user_a_id' => $ids[0], 'user_b_id' => $ids[1]],
                ['score' => $match['score'], 'reasons' => $match['reasons'], 'is_active' => true],
            );
        }

        $alerts->roommateMatches($user->id, $matches);

        return response()->json($profile->fresh());
    }

    public function matches(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = RoommatePreference::where('user_id', $user->id)->first();

        return response()->json($profile ? $this->matcher->matchesFor($user, $profile) : []);
    }
}
