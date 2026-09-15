<?php

namespace App\Http\Resources;

use App\Models\SyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SyncRun */
class SyncRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'is_active' => $this->status->isActive(),
            'progress' => $this->progressPercent(),
            'pages_total' => $this->pages_total,
            'pages_done' => $this->pages_done,
            'attempts' => $this->attempts,
            'reviews_fetched' => $this->reviews_fetched,
            'reviews_created' => $this->reviews_created,
            'reviews_updated' => $this->reviews_updated,
            'reviews_removed' => $this->reviews_removed,
            'error' => $this->error_code === null ? null : [
                'code' => $this->error_code,
                'message' => $this->error_message,
            ],
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
