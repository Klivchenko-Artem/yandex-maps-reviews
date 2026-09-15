<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationSnapshot;
use App\Models\ReviewRevision;
use Illuminate\Http\JsonResponse;

/**
 * «Было → стало» между парсингами: изменения карточки по соседним снимкам
 * и последние события по отзывам из журнала ревизий.
 */
class OrganizationHistoryController extends Controller
{
    private const LIMIT = 20;

    private const TRACKED_FIELDS = ['name', 'address', 'rating', 'ratings_count', 'reviews_count'];

    public function index(Organization $organization): JsonResponse
    {
        // На один больше, чтобы самому старому из показанных было с чем сравнить.
        $snapshots = $organization->snapshots()
            ->with('syncRun')
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT + 1)
            ->get()
            ->values();

        $entries = $snapshots->take(self::LIMIT)->map(function (OrganizationSnapshot $snapshot, int $i) use ($snapshots) {
            /** @var OrganizationSnapshot|null $previous */
            $previous = $snapshots->get($i + 1);

            $changes = [];
            foreach (self::TRACKED_FIELDS as $field) {
                if ($previous !== null && $previous->{$field} !== $snapshot->{$field}) {
                    $changes[$field] = ['old' => $previous->{$field}, 'new' => $snapshot->{$field}];
                }
            }

            return [
                'captured_at' => $snapshot->captured_at->toIso8601String(),
                'rating' => $snapshot->rating,
                'ratings_count' => $snapshot->ratings_count,
                'reviews_count' => $snapshot->reviews_count,
                'is_first' => $previous === null,
                'changes' => (object) $changes,
                'reviews_created' => $snapshot->syncRun?->reviews_created,
                'reviews_updated' => $snapshot->syncRun?->reviews_updated,
                'reviews_removed' => $snapshot->syncRun?->reviews_removed,
            ];
        });

        $revisions = ReviewRevision::query()
            ->where('organization_id', $organization->id)
            ->with('review:id,author_name')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (ReviewRevision $r) => [
                'event' => $r->event,
                'review_id' => $r->review_id,
                'author_name' => $r->review->author_name,
                'changes' => $r->changes,
                'created_at' => $r->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => ['snapshots' => $entries, 'review_revisions' => $revisions]]);
    }
}
