<?php

namespace App\Services\Sync;

use App\Enums\SyncStatus;
use App\Jobs\SyncOrganization;
use App\Models\Organization;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ставит синхронизацию в очередь, но не больше одной на организацию:
 * повторный клик «обновить» или повторное сохранение той же ссылки
 * возвращают уже идущий запуск.
 */
class SyncDispatcher
{
    /**
     * Запуск, который не обновлялся дольше этого, считаем брошенным
     * (воркер убит, сервер перезагружен), иначе организация зависнет «в работе» навсегда.
     * Порог не константа: он обязан быть больше и самой длинной паузы между повторами,
     * и таймаута задачи, и паузы после бана, иначе живой запуск объявят зависшим.
     */
    private const STALE_AFTER_MINUTES = 45;

    /** Для ещё не взятого воркером запуска порог большой: только если очередь явно потеряла задачу. */
    private const STALE_QUEUED_AFTER_HOURS = 48;

    public function dispatch(Organization $organization): SyncRun
    {
        return DB::transaction(function () use ($organization) {
            // Блокировка строки организации: два одновременных запроса не создадут два запуска.
            Organization::whereKey($organization->id)->lockForUpdate()->first();

            $active = $organization->activeSyncRun();

            if ($active !== null && ! $this->isStale($active)) {
                return $active;
            }

            $this->close($active);

            $run = $organization->syncRuns()->create(['status' => SyncStatus::Queued]);
            SyncOrganization::dispatch($run)->afterCommit();

            return $run;
        });
    }

    /**
     * Закрывает все зависшие запуски, не дожидаясь, пока кто-то нажмёт «Обновить».
     * Без этого организация с потерянной задачей стоит с неактивной кнопкой до самого порога.
     *
     * @return int сколько запусков закрыли
     */
    public function closeStale(): int
    {
        $closed = 0;

        SyncRun::query()
            ->whereIn('status', SyncStatus::activeValues())
            ->where(fn ($q) => $q
                ->where(fn ($active) => $active->where('status', '!=', SyncStatus::Queued->value)->where('updated_at', '<', $this->threshold(false)))
                ->orWhere(fn ($queued) => $queued->where('status', SyncStatus::Queued->value)->where('updated_at', '<', $this->threshold(true)))
            )
            ->each(function (SyncRun $run) use (&$closed) {
                $this->close($run);
                $closed++;
            });

        return $closed;
    }

    private function close(?SyncRun $run): void
    {
        $run?->update([
            'status' => SyncStatus::Failed,
            'error_code' => 'stale',
            'error_message' => 'Запуск завис и был прерван',
            'finished_at' => now(),
        ]);
    }

    private function isStale(SyncRun $run): bool
    {
        return $run->updated_at->lt($this->threshold($run->status === SyncStatus::Queued));
    }

    private function threshold(bool $queued): Carbon
    {
        // В очереди запуск не обновляется по определению: после ночного sync-all хвост из сотен
        // организаций законно ждёт часами. Если переставлять его в конец очереди, до него не дойдёт никогда.
        if ($queued) {
            return now()->subHours(self::STALE_QUEUED_AFTER_HOURS);
        }

        $minutes = max(
            self::STALE_AFTER_MINUTES,
            (int) ceil(max(SyncOrganization::BACKOFF) / 60) + 15,
            (int) ceil((int) config('yandex.block_cooldown') / 60) + 15,
            (int) ceil(SyncOrganization::TIMEOUT / 60) + 15,
        );

        return now()->subMinutes($minutes);
    }
}
