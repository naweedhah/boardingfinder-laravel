<?php

namespace App\Http\Requests;

use App\Enums\BoardingStatus;
use App\Enums\BoardingType;
use App\Enums\PreferredTenantGender;
use App\Support\Geo;
use App\Support\HtmlSanitizer;
use App\Support\Keys;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for creating and editing a listing. The client sends
 * `{ postData: {...}, postDetail: {...} }`; when editing, every field is optional.
 */
abstract class PostPayloadRequest extends FormRequest
{
    /** 'required' when creating, 'sometimes' when editing. */
    abstract protected function presence(): string;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $presence = $this->presence();

        return [
            'postData' => [$presence, 'array'],
            'postData.title' => [$presence, 'string', function (string $attribute, mixed $value, Closure $fail) {
                $length = mb_strlen(trim((string) $value));
                if ($length < 5 || $length > 60) {
                    $fail('Title must be between 5 and 60 characters.');
                }
            }],
            'postData.address' => [$presence, 'string', 'max:255'],
            // The Node API judged "location" by city (falling back to area).
            'postData.city' => [$presence, 'string', 'min:3', 'max:100'],
            'postData.area' => ['nullable', 'string', 'max:255'],
            'postData.rent' => [$presence, 'integer', 'min:1', 'max:1000000'],
            'postData.capacity' => [$presence, 'integer', 'min:1', 'max:50'],
            'postData.bathroomCount' => ['nullable', 'integer', 'min:0', 'max:20'],
            'postData.boardingType' => [$presence, Rule::enum(BoardingType::class)],
            'postData.preferredTenantGender' => ['sometimes', Rule::enum(PreferredTenantGender::class)],
            'postData.status' => ['sometimes', Rule::enum(BoardingStatus::class)],
            // Listings are Sri Lankan; a pin outside the island is a typo (or swapped lat/lng).
            'postData.latitude' => [$presence, 'numeric', 'between:'.Geo::SRI_LANKA['minLat'].','.Geo::SRI_LANKA['maxLat']],
            'postData.longitude' => [$presence, 'numeric', 'between:'.Geo::SRI_LANKA['minLng'].','.Geo::SRI_LANKA['maxLng']],
            'postData.images' => ['sometimes', 'array', 'max:20'],
            // Photos are uploaded to Cloudinary by the browser; only HTTPS URLs are accepted.
            'postData.images.*' => ['string', 'max:2048', 'url:https'],

            'postDetail' => [$presence, 'array'],
            'postDetail.description' => [$presence, 'string', 'max:20000', function (string $attribute, mixed $value, Closure $fail) {
                // The editor stores HTML; count the words a reader would see.
                $text = html_entity_decode(preg_replace('/<[^>]*>/', ' ', (string) $value));

                if (count(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY)) < 10) {
                    $fail('Description must be at least 10 words.');
                }
            }],
            'postDetail.wifi' => ['nullable', 'boolean'],
            'postDetail.mealsProvided' => ['nullable', 'boolean'],
            'postDetail.kitchenAccess' => ['nullable', 'boolean'],
            'postDetail.parking' => ['nullable', 'boolean'],
            'postDetail.attachedBathroom' => ['nullable', 'boolean'],
            'postDetail.furnished' => ['nullable', 'boolean'],
            'postDetail.petAllowed' => ['nullable', 'boolean'],
            'postDetail.nearestCampus' => ['nullable', 'string', 'max:255'],
            'postDetail.distanceToCampus' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'postDetail.distanceToBusStop' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'postDetail.distanceToTown' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'postDetail.rules' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'postData.title.required' => 'Title must be between 5 and 60 characters.',
            'postData.city.required' => 'Location must be at least 3 characters long.',
            'postData.city.min' => 'Location must be at least 3 characters long.',
            'postData.rent.required' => 'Monthly price (rent) must be greater than Rs. 0.',
            'postData.rent.integer' => 'Monthly price (rent) must be greater than Rs. 0.',
            'postData.rent.min' => 'Monthly price (rent) must be greater than Rs. 0.',
            'postData.capacity.required' => 'Available slots (capacity) must be a valid whole number of at least 1.',
            'postData.capacity.integer' => 'Available slots (capacity) must be a valid whole number of at least 1.',
            'postData.capacity.min' => 'Available slots (capacity) must be a valid whole number of at least 1.',
            'postDetail.description.required' => 'Description must be at least 10 words.',
            'postData.rent.max' => 'Monthly rent looks too high (maximum Rs. 1,000,000).',
            'postData.capacity.max' => 'A listing can have at most 50 slots.',
            'postData.latitude.between' => 'The map pin must be inside Sri Lanka.',
            'postData.longitude.between' => 'The map pin must be inside Sri Lanka.',
            'postData.images.*.url' => 'Each photo must be an https:// image URL.',
        ];
    }

    /**
     * Splits the validated payload into listing columns and detail columns (snake_case).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function payload(): array
    {
        $validated = $this->validated();

        $detail = Keys::snake($validated['postDetail'] ?? []);

        // Rich text is stored only after an allow-list pass; see HtmlSanitizer.
        if (array_key_exists('description', $detail)) {
            $detail['description'] = HtmlSanitizer::clean($detail['description']);
        }

        return [
            Keys::snake($validated['postData'] ?? []),
            $detail,
        ];
    }
}
