<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'external_id', 'author_name', 'author_avatar_url', 'rating', 'text',
        'business_reply', 'published_at', 'first_seen_at', 'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'published_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ReviewRevision::class);
    }

    /** @param Builder<Review> $query */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNull('removed_at');
    }
}
