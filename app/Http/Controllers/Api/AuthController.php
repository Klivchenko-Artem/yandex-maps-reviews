<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * SPA-аутентификация Sanctum: сессия в куке, CSRF через /sanctum/csrf-cookie.
 * Токены не выдаём, фронт живёт на том же домене.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): UserResource
    {
        // Сессию Sanctum поднимает только для запросов со своих доменов (SANCTUM_STATEFUL_DOMAINS).
        // Без этой проверки неверно настроенный стенд отвечал бы на вход безликим 500.
        abort_unless($request->hasSession(), 419, 'Запрос пришёл не с домена приложения: проверьте APP_URL и SANCTUM_STATEFUL_DOMAINS');

        $credentials = $request->only('email', 'password');

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'Неверный логин или пароль']);
        }

        $request->session()->regenerate();

        return new UserResource(Auth::guard('web')->user());
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
