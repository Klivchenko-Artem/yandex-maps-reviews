<?php

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Organization */
class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'external_id' => $this->external_id,
            'url' => $this->url,
            'name' => $this->name,
            'address' => $this->address,
            'rating' => $this->rating,
            'ratings_count' => $this->ratings_count,
            'reviews_count' => $this->reviews_count,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'sync' => new SyncRunResource($this->whenLoaded('latestSyncRun')),
        ];
    }
}
