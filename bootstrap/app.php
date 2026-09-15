<?php

use App\Services\YandexMaps\Exceptions\SourceException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Сессия и CSRF для запросов с нашего же фронта, это и есть SPA-режим Sanctum.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Во фреймворке тексты технические и английские («No query results for model [App\Models\...]»):
        // наружу они не нужны, а имя модели в ответе лишняя подсказка о внутренностях.
        $russian = [404 => 'Не найдено', 405 => 'Метод не поддерживается', 419 => 'Сессия устарела, обновите страницу', 429 => 'Слишком много запросов, подождите минуту'];
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) use ($russian) {
            $status = $response->getStatusCode();
            // 419 с собственным текстом (неверная настройка доменов) оставляем как есть, он и так понятный.
            $generic = $status !== 419 || ($response instanceof JsonResponse && ($response->getData(true)['message'] ?? null) === 'CSRF token mismatch.');
            if ($request->is('api/*') && isset($russian[$status]) && $generic && $response instanceof JsonResponse) {
                $response->setData(['message' => $russian[$status]]);
            }

            return $response;
        });

        // Ошибка источника, не пойманная по месту, не должна превращаться в безликий 500.
        $exceptions->render(fn (SourceException $e, Request $request) => response()->json([
            'message' => $e->getMessage(),
            'code' => $e->errorCode(),
        ], $e->isRetryable() ? 503 : 422));
    })->create();
