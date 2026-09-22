<?php

namespace App\Http\Controllers;

use App\Enums\BoardingStatus;
use App\Enums\BoardingType;
use App\Enums\NotificationType;
use App\Models\Post;
use App\Models\Watchlist;
use App\Services\DemandService;
use App\Services\GeminiService;
use App\Services\NotificationService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Throwable;

/**
 * The owner dashboard API (`/api/boardings`): a simplified, flat view of listings with photo
 * upload, AI helpers and a waitlist for full listings.
 */
class BoardingController extends Controller
{
    public function __construct(private DemandService $demand) {}

    /** Owners see their own listings; admins see all. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $posts = Post::withDemandCounts()
            ->with('postDetail')
            ->when(! $user->isAdmin(), fn ($query) => $query->where('owner_id', $user->id))
            ->latest()->latest('id')
            ->get();

        return response()->json($posts->map(fn (Post $post) => $this->format($post)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(creating: true));

        $post = DB::transaction(function () use ($request, $data) {
            $post = Post::create([
                'owner_id' => $request->user()->id,
                'title' => $data['title'],
                'rent' => (int) $data['price'],
                'address' => $data['location'],
                'images' => $this->storeImages($request),
                'preferred_tenant_gender' => $this->tenantGender($data['genderAllowed'] ?? null),
                'capacity' => (int) $data['capacity'],
                // The dashboard form has no map/city/type inputs yet; these are the Node API's defaults.
                'city' => $data['city'] ?? 'Colombo',
                'area' => $data['area'] ?? null,
                'latitude' => $data['latitude'] ?? '0.0',
                'longitude' => $data['longitude'] ?? '0.0',
                'boarding_type' => $data['boardingType'] ?? 'singleRoom',
                'status' => BoardingStatus::Available,
            ]);

            // The form's "facilities" free text is kept in the listing's rules field.
            $post->postDetail()->create([
                'description' => $data['description'],
                'rules' => $data['facilities'] ?? '',
            ]);

            return $post;
        })->fresh('postDetail');

        return response()->json(['message' => 'Boarding added successfully!', 'boarding' => $post], 201);
    }

    public function update(Request $request, Post $post): JsonResponse
    {
        Gate::authorize('update', $post);

        $data = $request->validate($this->rules(creating: false));

        DB::transaction(function () use ($request, $post, $data) {
            $changes = array_filter([
                'title' => $data['title'] ?? null,
                'rent' => isset($data['price']) ? (int) $data['price'] : null,
                'address' => $data['location'] ?? null,
                'capacity' => isset($data['capacity']) ? (int) $data['capacity'] : null,
                'preferred_tenant_gender' => isset($data['genderAllowed']) ? $this->tenantGender($data['genderAllowed']) : null,
            ], fn ($value) => $value !== null);

            // New photos replace the old set; sending none keeps the current photos.
            if ($request->hasFile('images')) {
                $changes['images'] = $this->storeImages($request);
            }

            $post->update($changes);

            $detail = array_filter([
                'description' => $data['description'] ?? null,
                'rules' => $data['features'] ?? null,
            ], fn ($value) => filled($value));

            if ($detail && $post->postDetail) {
                $post->postDetail->update($detail);
            }
        });

        return response()->json(['message' => 'Updated successfully', 'post' => $post->fresh()]);
    }

    public function destroy(Post $post): JsonResponse
    {
        Gate::authorize('delete', $post);

        $post->delete();

        return response()->json(['message' => 'Boarding deleted successfully!']);
    }

    /** Marking a listing available again notifies (and clears) everyone waiting for it. */
    public function updateStatus(Request $request, Post $post, NotificationService $notifications): JsonResponse
    {
        Gate::authorize('update', $post);

        $status = $request->validate([
            'status' => ['required', Rule::in(['Available', 'Full'])],
        ])['status'];

        $post->update(['status' => $status === 'Available' ? BoardingStatus::Available : BoardingStatus::Full]);

        $notified = 0;

        if ($status === 'Available') {
            $waiting = Watchlist::where('post_id', $post->id)->where('is_active', true)->get();

            foreach ($waiting as $entry) {
                $notifications->create(
                    userId: $entry->user_id,
                    type: NotificationType::BoardingAvailable,
                    title: 'A boarding you were waiting for is available',
                    message: "\"{$post->title}\" has a free slot again. Send a booking request before it fills up.",
                    metadata: ['postId' => $post->id],
                );
                $entry->update(['is_active' => false]);
                $notified++;
            }
        }

        return response()->json(['message' => 'Status updated', 'notifiedCount' => $notified]);
    }

    /** Students queue for a listing that is currently full. */
    public function joinWaitlist(Request $request, Post $post): JsonResponse
    {
        $user = $request->user();

        if ($post->owner_id === $user->id) {
            return response()->json(['message' => 'You cannot join the waitlist of your own listing'], 400);
        }

        $alreadyWaiting = Watchlist::where('user_id', $user->id)->where('post_id', $post->id)->where('is_active', true)->exists();

        if ($alreadyWaiting) {
            return response()->json(['message' => 'You are already on the waitlist!'], 400);
        }

        Watchlist::create(['user_id' => $user->id, 'post_id' => $post->id, 'is_active' => true]);

        return response()->json(['message' => 'Successfully added to the waitlist!'], 201);
    }

    public function waitlist(Post $post): JsonResponse
    {
        Gate::authorize('update', $post);

        $entries = Watchlist::with('user:id,username,full_name,email')
            ->where('post_id', $post->id)
            ->where('is_active', true)
            ->oldest()->oldest('id')
            ->get();

        return response()->json($entries->map(fn (Watchlist $entry) => [
            '_id' => $entry->id,
            'studentName' => $entry->user->displayName(),
            'studentEmail' => $entry->user->email,
            'createdAt' => $entry->created_at,
        ]));
    }

    public function generateDescription(Request $request, GeminiService $gemini): JsonResponse
    {
        $features = $request->validate(['features' => ['required', 'string', 'max:2000']])['features'];

        return $this->askGemini(
            fn () => ['description' => $gemini->generateDescription($features)],
            'Failed to generate description',
        );
    }

    public function verifyImage(Request $request, GeminiService $gemini): JsonResponse
    {
        $request->validate(
            ['image' => ['required', 'image', 'max:8192']],
            ['image.required' => 'No image uploaded for verification'],
        );

        return $this->askGemini(fn () => $gemini->analyzeRoomImage($request->file('image')), 'Failed to scan image');
    }

    /** @param  Closure(): array<string, mixed>  $call */
    private function askGemini(Closure $call, string $failure): JsonResponse
    {
        try {
            return response()->json($call());
        } catch (Throwable $e) {
            Log::error('Gemini request failed: '.$e->getMessage());

            return response()->json(['message' => $failure], 502);
        }
    }

    /**
     * Same limits the dashboard form enforces client-side, repeated here because the API can be
     * called directly.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(bool $creating): array
    {
        // A lone photo posted as `images` (not `images[]`) arrives as a single file, not a list.
        // (Read from the raw bag: `file()` would cache the converted value and hide the fix.)
        if (($single = request()->files->get('images')) instanceof SymfonyUploadedFile) {
            request()->files->set('images', [$single]);
        }

        $presence = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$presence, 'string', function (string $attribute, mixed $value, Closure $fail) {
                $length = mb_strlen(trim((string) $value));
                if ($length < 5 || $length > 60) {
                    $fail('Title must be between 5 and 60 characters.');
                }
            }],
            'location' => [$presence, 'string', 'min:3', 'max:255'],
            'price' => [$presence, 'integer', 'min:1'],
            'capacity' => [$presence, 'integer', 'min:1'],
            'genderAllowed' => ['nullable', 'string', 'max:16'],
            'description' => [$presence, 'string', function (string $attribute, mixed $value, Closure $fail) {
                $text = html_entity_decode(preg_replace('/<[^>]*>/', ' ', (string) $value));

                if (count(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY)) < 10) {
                    $fail('Description must be at least 10 words.');
                }
            }],
            'facilities' => ['nullable', 'string', 'max:2000'],
            'features' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'boardingType' => ['nullable', 'string', Rule::in(BoardingType::values())],
            'images' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:5'],
            'images.*' => ['image', 'max:8192'],
        ];
    }

    /** @return list<string> paths on the public disk */
    private function storeImages(Request $request): array
    {
        return collect($request->file('images', []))
            ->map(fn (UploadedFile $file) => $file->store('uploads', 'public'))
            ->all();
    }

    private function tenantGender(?string $value): string
    {
        $value = strtolower((string) $value);

        return in_array($value, ['male', 'female'], true) ? $value : 'any';
    }

    /**
     * The flat shape the owner dashboard reads (`_id`, `price`, `location`, "Available"/"Full").
     *
     * @return array<string, mixed>
     */
    private function format(Post $post): array
    {
        $figures = $this->demand->forPost($post);

        return [
            '_id' => $post->id,
            'title' => $post->title,
            'description' => $post->postDetail?->description ?? '',
            'price' => $post->rent,
            'location' => $post->address,
            'genderAllowed' => $post->preferred_tenant_gender,
            'status' => $post->status === BoardingStatus::Available ? 'Available' : 'Full',
            'capacity' => $post->capacity,
            'images' => $post->images,
            // Feed the dashboard's per-listing engagement chart.
            'savedCount' => $figures['savedCount'],
            'inquiryCount' => $figures['inquiryCount'],
            'bookingCount' => $figures['bookingCount'],
            'remainingSlots' => $figures['remainingSlots'],
        ];
    }
}
