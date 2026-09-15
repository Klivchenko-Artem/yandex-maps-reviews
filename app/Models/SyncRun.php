<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRun extends Model
{
    protected $fillable = [
        'organization_id', 'status', 'attempts', 'blocked_waits', 'pages_total', 'pages_done', 'reviews_fetched',
        'reviews_created', 'reviews_updated', 'reviews_removed', 'error_code', 'error_message',
        'started_at', 'finished_at',
    ];

    protected $attributes = [
        'status' => 'queued',
        'attempts' => 0,
        'blocked_waits' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => SyncStatus::class,
            'attempts' => 'integer',
            'blocked_waits' => 'integer',
            'pages_total' => 'integer',
            'pages_done' => 'integer',
            'reviews_fetched' => 'integer',
            'reviews_created' => 'integer',
            'reviews_updated' => 'integer',
            'reviews_removed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function progressPercent(): int
    {
        return match (true) {
            $this->status === SyncStatus::Completed => 100,
            ! $this->pages_total => 0,
            default => (int) min(99, floor($this->pages_done / $this->pages_total * 100)),
        };
    }
}
