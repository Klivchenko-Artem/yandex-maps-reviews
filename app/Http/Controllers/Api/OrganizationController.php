<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\Sync\SyncDispatcher;
use App\Services\YandexMaps\Exceptions\InvalidOrganizationLink;
use App\Services\YandexMaps\Exceptions\SourceException;
use App\Services\YandexMaps\OrganizationLinkResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizations = $request->user()->organizations()
            ->with('latestSyncRun')
            ->latest('id')
            ->get();

        return OrganizationResource::collection($organizations);
    }

    /**
     * Сохраняет ссылку и сразу ставит парсинг в очередь. Ответ парсинга не ждёт:
     * фронт получает организацию с запуском и опрашивает его прогресс.
     */
    public function store(StoreOrganizationRequest $request, OrganizationLinkResolver $resolver, SyncDispatcher $dispatcher): JsonResponse
    {
        $url = $request->validated('url');

        try {
            $externalId = $resolver->resolve($url);
        } catch (InvalidOrganizationLink $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        } catch (SourceException $e) {
            // Сюда попадаем, только если разворачивание короткой ссылки упёрлось в сеть или блокировку.
            return response()->json([
                'message' => 'Не удалось открыть короткую ссылку. Попробуйте полную ссылку на карточку организации.',
                'code' => $e->errorCode(),
            ], 503);
        }

        $organization = $request->user()->organizations()->firstOrCreate(
            ['source' => 'yandex', 'external_id' => $externalId],
            ['url' => $url],
        );
        $organization->update(['url' => $url]);

        $dispatcher->dispatch($organization);

        return $this->resource($organization)
            ->response()
            ->setStatusCode($organization->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Organization $organization): OrganizationResource
    {
        return $this->resource($organization);
    }

    public function destroy(Organization $organization): Response
    {
        $organization->delete();

        return response()->noContent();
    }

    private function resource(Organization $organization): OrganizationResource
    {
        $organization->load('latestSyncRun');

        return new OrganizationResource($organization);
    }
}
