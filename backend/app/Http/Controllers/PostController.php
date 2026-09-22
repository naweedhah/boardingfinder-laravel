<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Models\BookingRequest;
use App\Models\Post;
use App\Models\SavedPost;
use App\Services\AlertNotifier;
use App\Services\BookingException;
use App\Services\BookingService;
use App\Services\DemandService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $city = trim((string) $request->query('city'));
        $area = trim((string) $request->query('area'));
        $type = trim((string) $request->query('boardingType'));
        $gender = trim((string) $request->query('preferredTenantGender'));
        $status = trim((string) $request->query('status'));
        $capacity = (int) $request->query('capacity');
        $minPrice = (int) $request->query('minPrice');
        $maxPrice = (int) $request->query('maxPrice');

        // A "city" search also matches listings whose neighbourhood carries that name, and vice versa.
        $posts = Post::query()
            ->withDemandCounts()
            ->when($city !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('city', $city)->orWhere('area', $city)))
            ->when($area !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('area', $area)->orWhere('city', $area)))
            ->when($type !== '', fn (Builder $q) => $q->where('boarding_type', $type))
            ->when($gender !== '' && $gender !== 'any', fn (Builder $q) => $q->where('preferred_tenant_gender', $gender))
            ->when($status !== '', fn (Builder $q) => $q->where('status', $status))
            ->when($capacity > 0, fn (Builder $q) => $q->where('capacity', $capacity))
            ->when($minPrice > 0, fn (Builder $q) => $q->where('rent', '>=', $minPrice))
            ->when($maxPrice > 0, fn (Builder $q) => $q->where('rent', '<=', $maxPrice))
            ->latest()->latest('id')
            ->get();

        return PostResource::collection($posts);
    }

    public function demandOverview(DemandService $demand): JsonResponse
    {
        return response()->json($demand->overview());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $post = Post::withDemandCounts()
            ->with(['postDetail', 'owner:id,username,avatar,phone'])
            ->findOrFail($id);

        $isSaved = false;
        $booking = null;

        // Public page, but personalised when the visitor happens to be logged in.
        if ($user = Auth::guard('token')->user()) {
            $isSaved = SavedPost::where('user_id', $user->id)->where('post_id', $post->id)->exists();
            $booking = BookingRequest::where('post_id', $post->id)->where('student_id', $user->id)->latest()->first();
        }

        return response()->json([
            ...(new PostResource($post))->resolve(),
            'isSaved' => $isSaved,
            'bookingRequestStatus' => $booking?->status,
            'bookingRequestId' => $booking?->id,
        ]);
    }

    public function store(StorePostRequest $request, AlertNotifier $alerts): JsonResponse
    {
        [$postData, $detail] = $request->payload();

        $post = DB::transaction(function () use ($request, $postData, $detail) {
            $post = Post::create([...$postData, 'owner_id' => $request->user()->id]);
            $post->postDetail()->create($detail);

            return $post;
        })->fresh('postDetail');

        $alerts->watchlistAndSearchMatches($post);
        $alerts->demandSpike($post);

        return response()->json($post, 201);
    }

    public function update(UpdatePostRequest $request, Post $post, AlertNotifier $alerts): JsonResponse
    {
        [$postData, $detail] = $request->payload();
        $previousRent = $post->rent;

        DB::transaction(function () use ($post, $postData, $detail) {
            $post->update($postData);

            if ($detail) {
                $post->postDetail
                    ? $post->postDetail->update($detail)
                    : $post->postDetail()->create($detail + ['description' => '']);
            }
        });

        $post = $post->fresh('postDetail');

        $alerts->priceDrop($post, $previousRent);
        $alerts->watchlistAndSearchMatches($post);

        return response()->json($post);
    }

    public function destroy(Post $post): JsonResponse
    {
        Gate::authorize('delete', $post);

        $post->delete();

        return response()->json(['message' => 'Post deleted']);
    }

    /** A student asks to book a slot; the owner then approves or rejects from their dashboard. */
    public function book(Request $request, Post $post, BookingService $bookings): JsonResponse
    {
        $user = $request->user();

        if (! $user->isStudent()) {
            return response()->json(['message' => 'Only students can send booking requests'], 403);
        }

        if ($post->owner_id === $user->id) {
            return response()->json(['message' => 'You cannot book your own listing'], 400);
        }

        $applicant = $request->validate([
            'applicantName' => ['nullable', 'string', 'max:255'],
            'applicantPhone' => ['nullable', 'string', 'max:32'],
            'applicantAddress' => ['nullable', 'string', 'max:255'],
            'applicantNIC' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $result = $bookings->request($user, $post, $applicant);
        } catch (BookingException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        return response()->json([
            'message' => $result['created'] ? 'Booking request sent' : 'Booking request already sent',
            'bookingRequest' => $result['request'],
        ], $result['created'] ? 201 : 200);
    }
}
