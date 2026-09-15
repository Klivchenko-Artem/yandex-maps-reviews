<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SyncRunResource;
use App\Models\Organization;
use App\Services\Sync\SyncDispatcher;
use Illuminate\Http\JsonResponse;

class OrganizationSyncController extends Controller
{
    /** Ручной перезапуск парсинга. Если парсинг уже идёт, вернёт текущий запуск. */
    public function store(Organization $organization, SyncDispatcher $dispatcher): JsonResponse
    {
        return (new SyncRunResource($dispatcher->dispatch($organization)))
            ->response()
            ->setStatusCode(202);
    }
}
