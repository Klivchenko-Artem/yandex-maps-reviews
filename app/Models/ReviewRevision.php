<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewRevision extends Model
{
    public const UPDATED_AT = null;

    public const EVENT_CHANGED = 'changed';

    public const EVENT_REMOVED = 'removed';

    public const EVENT_RESTORED = 'restored';

    protected $fillable = ['review_id', 'organization_id', 'sync_run_id', 'event', 'changes'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
