<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Models\Organization;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaleSyncRunsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_closes_abandoned_run_without_waiting_for_a_click(): void
    {
        $organization = Organization::factory()->create();
        $run = $organization->syncRuns()->create(['status' => SyncStatus::Running]);
        // Воркера убили посреди обхода: запись больше никто не трогает.
        SyncRun::whereKey($run->id)->update(['updated_at' => now()->subHours(3)]);

        $this->artisan('organizations:close-stale')->assertSuccessful();

        $run = $run->fresh();
        $this->assertSame(SyncStatus::Failed, $run->status);
        $this->assertSame('stale', $run->error_code);
    }

    public function test_live_run_is_not_declared_stale_because_of_a_long_block_pause(): void
    {
        config(['yandex.block_cooldown' => 7200]);

        $organization = Organization::factory()->create();
        $run = $organization->syncRuns()->create(['status' => SyncStatus::Retrying]);
        // Задача ждёт конца паузы после бана: она жива, просто ей нечего делать.
        SyncRun::whereKey($run->id)->update(['updated_at' => now()->subHour()]);

        $this->artisan('organizations:close-stale')->assertSuccessful();

        $this->assertSame(SyncStatus::Retrying, $run->fresh()->status);
    }

    public function test_queued_run_keeps_its_place_in_a_long_night_queue(): void
    {
        $organization = Organization::factory()->create();
        $run = $organization->syncRuns()->create(['status' => SyncStatus::Queued]);
        SyncRun::whereKey($run->id)->update(['updated_at' => now()->subHours(6)]);

        $this->artisan('organizations:close-stale')->assertSuccessful();

        $this->assertSame(SyncStatus::Queued, $run->fresh()->status);
    }
}
