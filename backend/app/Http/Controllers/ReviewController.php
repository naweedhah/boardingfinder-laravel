<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    private const AUTHOR = 'user:id,username,full_name,avatar';

    public function index(Post $post): JsonResponse
    {
        $reviews = $post->reviews()->with(self::AUTHOR)->latest()->latest('id')->get();

        return response()->json([
            'reviews' => $reviews,
            'avgRating' => $reviews->isEmpty() ? null : round($reviews->avg('rating'), 1),
            'count' => $reviews->count(),
        ]);
    }

    /** One review per student per listing: posting again edits the existing one. */
    public function upsert(Request $request, Post $post): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], ['rating.*' => 'Rating must be between 1 and 5']);

        if (! $request->user()->isStudent()) {
            return response()->json(['message' => 'Only students can leave reviews'], 403);
        }

        $review = Review::updateOrCreate(
            ['user_id' => $request->user()->id, 'post_id' => $post->id],
            ['rating' => $data['rating'], 'comment' => $data['comment'] ?? null],
        )->load(self::AUTHOR);

        return response()->json(['message' => 'Review saved', 'review' => $review]);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        $review = Review::where('user_id', $request->user()->id)->where('post_id', $post->id)->first();

        if (! $review) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        $review->delete();

        return response()->json(['message' => 'Review deleted']);
    }
}
