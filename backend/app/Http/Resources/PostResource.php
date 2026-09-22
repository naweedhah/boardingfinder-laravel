<?php

namespace App\Http\Resources;

use App\Models\Post;
use App\Services\DemandService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A listing plus its live availability and demand figures.
 * Expects the post to be loaded with {@see Post::withDemandCounts()}.
 *
 * @property Post $resource
 */
class PostResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            ...app(DemandService::class)->forPost($this->resource),
        ];
    }
}
