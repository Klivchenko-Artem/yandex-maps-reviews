<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Jobs\SyncOrganization;
use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_saving_link_creates_organization_and_queues_parsing(): void
    {
        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/yandeks/1124715036/reviews/'])
            ->assertCreated()
            ->assertJsonPath('data.external_id', '1124715036')
            ->assertJsonPath('data.ratings_count', 0)
            ->assertJsonPath('data.sync.status', 'queued')
            ->assertJsonPath('data.sync.is_active', true);

        $organization = $this->user->organizations()->sole();
        $this->assertSame(1, $organization->syncRuns()->count());
        Queue::assertPushedOn('parsing', SyncOrganization::class, fn (SyncOrganization $job) => $job->run->organization_id === $organization->id);
    }

    public function test_saving_same_organization_twice_does_not_duplicate_anything(): void
    {
        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/yandeks/1124715036/'])->assertCreated();
        // Другая форма ссылки на ту же организацию, пока первый парсинг ещё в очереди.
        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/?oid=1124715036&ol=biz'])
            ->assertOk()
            ->assertJsonPath('data.url', 'https://yandex.ru/maps/?oid=1124715036&ol=biz');

        $this->assertSame(1, Organization::count());
        $this->assertSame(1, Organization::first()->syncRuns()->count());
        Queue::assertPushed(SyncOrganization::class, 1);
    }

    public function test_short_link_is_resolved_to_organization(): void
    {
        Http::fake([
            'yandex.ru/maps/-/CHUEjI0h' => Http::response('', 301, ['Location' => 'https://yandex.ru/maps/org/just/1337567415?si=abc']),
        ]);

        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/-/CHUEjI0h'])
            ->assertCreated()
            ->assertJsonPath('data.external_id', '1337567415');
    }

    public function test_short_link_leading_nowhere_is_validation_error(): void
    {
        Http::fake(['yandex.ru/maps/-/*' => Http::response('', 301, ['Location' => 'https://yandex.ru/maps/213/moscow/'])]);

        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/-/CHUEjI0h'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->assertSame(0, Organization::count());
    }

    public function test_short_link_when_yandex_is_down_is_503_not_500(): void
    {
        Http::fake(['yandex.ru/*' => Http::response('', 502)]);

        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/-/CHUEjI0h'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'unavailable');
    }

    public function test_link_validation(): void
    {
        $this->postJson('/api/organizations', [])->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->postJson('/api/organizations', ['url' => 'https://2gis.ru/taganrog/firm/1'])->assertJsonValidationErrors('url');
        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/213/moscow/'])->assertJsonValidationErrors('url');
        $this->postJson('/api/organizations', ['url' => ['array']])->assertJsonValidationErrors('url');

        Queue::assertNothingPushed();
    }

    public function test_foreign_organization_is_not_found(): void
    {
        $foreign = Organization::factory()->create();

        $this->getJson("/api/organizations/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/organizations/{$foreign->id}/reviews")->assertNotFound();
        $this->getJson("/api/organizations/{$foreign->id}/history")->assertNotFound();
        $this->postJson("/api/organizations/{$foreign->id}/sync")->assertNotFound();
        $this->deleteJson("/api/organizations/{$foreign->id}")->assertNotFound();

        $this->assertModelExists($foreign);

        // Ни английского текста фреймворка, ни имени модели в ответе.
        $this->getJson("/api/organizations/{$foreign->id}")->assertExactJson(['message' => 'Не найдено']);
    }

    public function test_validation_messages_are_in_russian(): void
    {
        $organization = Organization::factory()->for($this->user)->create();
        $this->getJson("/api/organizations/{$organization->id}/reviews?page=abc")
            ->assertUnprocessable()
            ->assertJsonPath('errors.page.0', 'Поле «номер страницы» должно быть целым числом.');

        $this->postJson('/api/organizations', [])->assertJsonPath('errors.url.0', 'Заполните поле «ссылка».');
    }

    public function test_reviews_are_paginated_by_50_newest_first_without_removed(): void
    {
        $organization = Organization::factory()->for($this->user)->create();
        Review::factory()->count(120)->for($organization)->sequence(fn ($s) => ['published_at' => now()->subDays($s->index)])->create();
        Review::factory()->for($organization)->create(['published_at' => now()->addDay(), 'removed_at' => now(), 'author_name' => 'Удалённый']);

        $first = $this->getJson("/api/organizations/{$organization->id}/reviews")
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.total', 120)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonStructure(['data' => [['id', 'author' => ['name', 'avatar_url'], 'rating', 'text', 'business_reply', 'published_at']]]);

        $this->assertNotSame('Удалённый', $first->json('data.0.author.name'));
        $dates = array_column($first->json('data'), 'published_at');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates);

        $this->getJson("/api/organizations/{$organization->id}/reviews?page=3")->assertOk()->assertJsonCount(20, 'data');
        $this->getJson("/api/organizations/{$organization->id}/reviews?page=abc")->assertUnprocessable();
    }

    public function test_manual_resync_returns_running_run_instead_of_queueing_second(): void
    {
        $organization = Organization::factory()->for($this->user)->create();

        $first = $this->postJson("/api/organizations/{$organization->id}/sync")->assertAccepted()->json('data.id');
        $second = $this->postJson("/api/organizations/{$organization->id}/sync")->assertAccepted()->json('data.id');

        $this->assertSame($first, $second);
        Queue::assertPushed(SyncOrganization::class, 1);
    }

    public function test_stale_run_is_closed_and_new_one_is_queued(): void
    {
        $organization = Organization::factory()->for($this->user)->create();
        $stale = $organization->syncRuns()->create(['status' => SyncStatus::Running]);
        $stale->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        $newId = $this->postJson("/api/organizations/{$organization->id}/sync")->assertAccepted()->json('data.id');

        $this->assertNotSame($stale->id, $newId);
        $this->assertSame(SyncStatus::Failed, $stale->fresh()->status);
        $this->assertSame('stale', $stale->fresh()->error_code);
    }

    public function test_list_shows_only_own_organizations(): void
    {
        $own = Organization::factory()->for($this->user)->create(['reviews_count' => 5864]);
        Review::factory()->count(3)->for($own)->create();
        Organization::factory()->create();

        $this->getJson('/api/organizations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reviews_count', 5864);
    }

    public function test_delete_removes_organization_with_reviews(): void
    {
        $organization = Organization::factory()->for($this->user)->create();
        Review::factory()->count(2)->for($organization)->create();

        $this->deleteJson("/api/organizations/{$organization->id}")->assertNoContent();

        $this->assertModelMissing($organization);
        $this->assertSame(0, Review::count());
    }

    public function test_link_longer_than_the_column_is_validation_error(): void
    {
        // Схему дописывает нормализация, поэтому длину надо мерить уже после неё.
        $url = 'yandex.ru/maps/org/kofeynya/1124715036/?tail='.str_repeat('a', 2048 - 46);

        $this->postJson('/api/organizations', ['url' => $url])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');
    }

    public function test_link_with_oid_query_is_accepted(): void
    {
        $this->postJson('/api/organizations', ['url' => 'https://maps.yandex.ru/?oid=1124715036&ol=biz'])
            ->assertCreated()
            ->assertJsonPath('data.external_id', '1124715036');
    }
}
