<?php

namespace App\Services;

use App\Enums\BoardingStatus;
use App\Models\BookingRequest;
use App\Models\Inquiry;
use App\Models\Post;
use App\Models\Watchlist;
use Illuminate\Support\Collection;

/**
 * Demand analytics. Every signal is weighted by how strongly it indicates intent:
 * a save is a soft signal (x2), an inquiry a conversation (x3), a booking request a commitment (x4).
 */
class DemandService
{
    public const SAVE_WEIGHT = 2;

    public const INQUIRY_WEIGHT = 3;

    public const BOOKING_WEIGHT = 4;

    public function level(int $score): string
    {
        return match (true) {
            $score >= 12 => 'high',
            $score >= 6 => 'medium',
            default => 'low',
        };
    }

    /**
     * Demand and availability figures for a listing loaded with {@see Post::withDemandCounts()}.
     *
     * @return array<string, int|string>
     */
    public function forPost(Post $post): array
    {
        $saved = (int) $post->saved_posts_count;
        $inquiries = (int) $post->inquiries_count;
        $bookings = (int) $post->booking_requests_count;
        $score = $saved * self::SAVE_WEIGHT + $inquiries * self::INQUIRY_WEIGHT + $bookings * self::BOOKING_WEIGHT;

        return [
            'remainingSlots' => max(0, $post->capacity - (int) $post->active_booking_requests_count),
            'savedCount' => $saved,
            'inquiryCount' => $inquiries,
            'bookingCount' => $bookings,
            'demandScore' => $score,
            'demandLevel' => $this->level($score),
        ];
    }

    /**
     * Per-area demand for the map/heatmap: listings vs. saved-search alerts, inquiries and bookings.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        /** @var array<string, array<string, mixed>> $areas */
        $areas = [];

        // Returns the bucket key for a city/area pair, creating the bucket on first use.
        $bucket = function (?string $city, ?string $name) use (&$areas): string {
            $key = mb_strtolower(trim($city ?: 'unknown')).'::'.mb_strtolower(trim($name ?: $city ?: 'unknown'));

            $areas[$key] ??= [
                'key' => $key,
                'city' => $city ?: 'Unknown',
                'area' => $name ?: $city ?: 'Unknown',
                'latitudes' => [],
                'longitudes' => [],
                'listings' => 0,
                'searches' => 0,
                'bookings' => 0,
                'inquiries' => 0,
                'samplePosts' => [],
            ];

            return $key;
        };

        foreach (Post::where('status', BoardingStatus::Available)
            ->get(['id', 'title', 'city', 'area', 'latitude', 'longitude', 'rent']) as $post) {
            $key = $bucket($post->city, $post->area);
            $areas[$key]['listings']++;

            if (is_numeric($post->latitude)) {
                $areas[$key]['latitudes'][] = (float) $post->latitude;
            }
            if (is_numeric($post->longitude)) {
                $areas[$key]['longitudes'][] = (float) $post->longitude;
            }
            if (count($areas[$key]['samplePosts']) < 3) {
                $areas[$key]['samplePosts'][] = ['id' => $post->id, 'title' => $post->title, 'rent' => $post->rent];
            }
        }

        foreach (Watchlist::where('is_active', true)->whereNull('post_id')->get(['city', 'area']) as $alert) {
            $areas[$bucket($alert->city, $alert->area)]['searches']++;
        }

        foreach (BookingRequest::with('post:id,city,area')->get(['id', 'post_id']) as $booking) {
            $areas[$bucket($booking->post?->city, $booking->post?->area)]['bookings']++;
        }

        foreach (Inquiry::with('post:id,city,area')->get(['id', 'post_id']) as $inquiry) {
            $areas[$bucket($inquiry->post?->city, $inquiry->post?->area)]['inquiries']++;
        }

        /** @var Collection<int, array<string, mixed>> $all */
        $all = collect($areas)
            ->map(function (array $entry): array {
                $score = $entry['searches'] * self::SAVE_WEIGHT
                    + $entry['inquiries'] * self::INQUIRY_WEIGHT
                    + $entry['bookings'] * self::BOOKING_WEIGHT;

                return [
                    'key' => $entry['key'],
                    'city' => $entry['city'],
                    'area' => $entry['area'],
                    'listings' => $entry['listings'],
                    'searches' => $entry['searches'],
                    'bookings' => $entry['bookings'],
                    'inquiries' => $entry['inquiries'],
                    'demandScore' => $score,
                    'demandLevel' => $this->level($score),
                    'latitude' => $entry['latitudes'] ? array_sum($entry['latitudes']) / count($entry['latitudes']) : null,
                    'longitude' => $entry['longitudes'] ? array_sum($entry['longitudes']) / count($entry['longitudes']) : null,
                    'samplePosts' => $entry['samplePosts'],
                ];
            })
            ->filter(fn (array $entry) => $entry['listings'] > 0)
            ->sortByDesc('demandScore')
            ->values();

        $high = $all->where('demandLevel', 'high')->take(5)->values();
        $medium = $all->where('demandLevel', 'medium')->take(5)->values();
        $low = $all
            ->sort(fn (array $a, array $b) => [$a['demandScore'], $b['listings']] <=> [$b['demandScore'], $a['listings']])
            ->take(5)
            ->values();

        return [
            'summary' => [
                'totalAreasTracked' => $all->count(),
                'highDemandCount' => $high->count(),
                'mediumDemandCount' => $medium->count(),
                'lowDemandCount' => $low->count(),
            ],
            'highDemandAreas' => $high,
            'lowDemandAreas' => $low,
            'allAreas' => $all,
        ];
    }
}
