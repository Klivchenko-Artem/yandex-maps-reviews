<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\Sync\OrganizationSyncService;
use App\Services\YandexMaps\Exceptions\SourceBlocked;
use App\Services\YandexMaps\Exceptions\SourceException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Фоновая синхронизация одной организации.
 *
 * Повторы решаются здесь, а не голым `$tries`: временные сбои (сеть, 5xx)
 * ждут по нарастающей, при капче ждём, пока предохранитель троттла снимет паузу,
 * а «источник изменился» и «организации нет» не повторяются вовсе, там нужен человек.
 */
class SyncOrganization implements ShouldQueue
{
    use Queueable;

    /** Сколько раз задача может сорваться, реально сходив в Яндекс. */
    public const MAX_ATTEMPTS = 5;

    /**
     * Ожидания общей паузы после бана считаются отдельно: пока она идёт, запросов не было
     * вовсе, и тратить на это бюджет повторов нечестно. Иначе бан дольше часа отправлял бы
     * в «ошибку» всю ночную пачку, хотя Яндекса мы за это время ни разу не потревожили.
     */
    public const MAX_BLOCKED_WAITS = 15;

    /** Жёсткий потолок для воркера; настоящий бюджет считает сама задача. */
    public int $tries = self::MAX_ATTEMPTS + self::MAX_BLOCKED_WAITS;

    /** Организацию отключили, пока задача ждала в очереди: запуск удалён каскадом, делать нечего. */
    public bool $deleteWhenMissingModels = true;

    /**
     * Худший случай одной попытки: 12 страниц, каждая с двумя перезапросами по 20 с таймаута
     * и паузами 3 и 6 с, то есть около 70 с на страницу. Меньший таймаут убивал бы задачу
     * молча, посреди обхода, и запуск оставался бы висеть «в работе».
     */
    public const TIMEOUT = 1200;

    public int $timeout = self::TIMEOUT;

    /** @var list<int> секунды ожидания перед 2-й, 3-й, 4-й и 5-й попыткой */
    public const BACKOFF = [30, 120, 600, 1800];

    public function __construct(public SyncRun $run)
    {
        $this->onQueue('parsing');
    }

    /**
     * Дубли запусков отсекает SyncDispatcher на уровне базы. Это страховка на случай,
     * если две задачи по одной организации всё же окажутся в очереди одновременно.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->run->organization_id))->releaseAfter(60)->expireAfter($this->timeout + 60)];
    }

    public function handle(OrganizationSyncService $sync): void
    {
        // Запуск могли признать зависшим и закрыть или удалить вместе с организацией,
        // пока задача ждала повтора. Тогда её работа уже не нужна.
        if (! $this->reloadRun() || ! $this->run->status->isActive()) {
            return;
        }

        try {
            $sync->run($this->run);
        } catch (SourceException $e) {
            $this->retryOrFail($sync, $e);
        } catch (Throwable $e) {
            // Организацию отключили посреди обхода: вставка отзыва упала на внешнем ключе.
            // Это действие пользователя, а не сбой, поэтому без повторов и без записи в failed_jobs.
            if (! SyncRun::whereKey($this->run->id)->exists()) {
                return;
            }

            // Непредвиденное (база, баг) пусть очередь повторит сама, но статус на фронте не должен висеть «в работе».
            if ($this->spentAttempts() < self::MAX_ATTEMPTS) {
                $sync->markRetrying($this->run, $e, $this->backoffFor($this->spentAttempts()));
            }

            throw $e;
        }
    }

    private function retryOrFail(OrganizationSyncService $sync, SourceException $e): void
    {
        $blocked = $e instanceof SourceBlocked;

        if ($blocked) {
            // Считаем отдельно от попыток: это ожидание, а не сорвавшаяся работа.
            $this->run->increment('blocked_waits');
        }

        $outOfBudget = $blocked
            ? $this->run->blocked_waits >= self::MAX_BLOCKED_WAITS
            : $this->spentAttempts() >= self::MAX_ATTEMPTS;

        if (! $e->isRetryable() || $outOfBudget) {
            $sync->markFailed($this->run, $e);
            $this->fail($e);

            return;
        }

        $delay = $this->delayFor($e);
        $sync->markRetrying($this->run, $e, $delay);
        $this->release($delay);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return self::BACKOFF;
    }

    public function failed(?Throwable $e): void
    {
        if ($this->reloadRun() && $this->run->status->isActive()) {
            app(OrganizationSyncService::class)->markFailed($this->run, $e ?? new \RuntimeException('Задача прервана'));
        }
    }

    private function reloadRun(): bool
    {
        $fresh = SyncRun::find($this->run->id);
        if ($fresh === null) {
            return false;
        }
        $this->run = $fresh;

        return true;
    }

    /** Попытки, в которых мы действительно ходили к Яндексу. */
    private function spentAttempts(): int
    {
        return max($this->attempts() - $this->run->blocked_waits, 1);
    }

    private function delayFor(SourceException $e): int
    {
        $delay = $this->backoffFor($this->spentAttempts());

        if ($e instanceof SourceBlocked) {
            // Разбегаемся случайно, чтобы 50 отложенных задач не проснулись в одну секунду.
            $delay = max($delay, $e->retryAfter()) + random_int(5, 120);
        }

        return $delay;
    }

    private function backoffFor(int $attempt): int
    {
        return self::BACKOFF[min(max($attempt - 1, 0), count(self::BACKOFF) - 1)];
    }
}
