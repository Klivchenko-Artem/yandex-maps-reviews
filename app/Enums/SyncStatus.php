<?php

namespace App\Enums;

enum SyncStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    /** Попытка сорвалась по временной причине, задача ждёт повтора. */
    case Retrying = 'retrying';
    case Completed = 'completed';
    case Failed = 'failed';
    /** Ответ Яндекса не совпал со схемой, парсер надо чинить руками. */
    case SourceChanged = 'source_changed';

    /** @return list<self> */
    public static function active(): array
    {
        return [self::Queued, self::Running, self::Retrying];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return array_map(fn (self $status) => $status->value, self::active());
    }
}
