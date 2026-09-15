<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'source', 'external_id', 'url', 'name', 'address',
        'rating', 'ratings_count', 'reviews_count', 'last_synced_at',
    ];

    /** Совпадают с дефолтами миграции, чтобы только что созданная модель не отдавала null вместо 0. */
    protected $attributes = [
        'source' => 'yandex',
        'ratings_count' => 0,
        'reviews_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'ratings_count' => 'integer',
            'reviews_count' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    public function latestSyncRun(): HasOne
    {
        return $this->hasOne(SyncRun::class)->latestOfMany();
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(OrganizationSnapshot::class);
    }

    public function activeSyncRun(): ?SyncRun
    {
        return $this->syncRuns()->whereIn('status', SyncStatus::activeValues())->latest('id')->first();
    }
}
