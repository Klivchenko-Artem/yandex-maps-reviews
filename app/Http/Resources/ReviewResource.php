<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Review */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author' => [
                'name' => $this->author_name,
                'avatar_url' => $this->author_avatar_url,
            ],
            'rating' => $this->rating,
            'text' => $this->text,
            'business_reply' => $this->business_reply,
            'published_at' => $this->published_at->toIso8601String(),
        ];
    }
}
