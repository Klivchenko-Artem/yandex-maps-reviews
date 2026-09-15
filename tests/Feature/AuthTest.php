<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Без Referer своего домена Sanctum считает запрос сторонним и не поднимает сессию.
        $this->withHeaders(['Referer' => 'http://localhost/login', 'Accept' => 'application/json']);
    }

    public function test_seeded_user_can_log_in_and_see_profile(): void
    {
        $this->seed();

        $this->postJson('/api/login', ['email' => 'demo@example.com', 'password' => 'demo12345'])
            ->assertOk()
            ->assertJsonPath('data.email', 'demo@example.com')
            ->assertJsonMissingPath('data.password');

        $this->getJson('/api/user')->assertOk()->assertJsonPath('data.email', 'demo@example.com');
    }

    public function test_wrong_password_is_validation_error_without_hint(): void
    {
        User::factory()->create(['email' => 'demo@example.com']);

        $this->postJson('/api/login', ['email' => 'demo@example.com', 'password' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Неверный логин или пароль');

        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_login_requires_fields(): void
    {
        $this->postJson('/api/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);

        $this->postJson('/api/login', ['email' => 'не почта', 'password' => 'x'])
            ->assertJsonPath('errors.email.0', 'Поле «логин» должно быть корректным email.');
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'demo@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'demo@example.com', 'password' => 'nope'])->assertUnprocessable();
        }

        $this->postJson('/api/login', ['email' => 'demo@example.com', 'password' => 'nope'])->assertTooManyRequests();
    }

    public function test_logout_ends_session(): void
    {
        $this->seed();
        $this->postJson('/api/login', ['email' => 'demo@example.com', 'password' => 'demo12345'])->assertOk();

        $this->postJson('/api/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_api_is_closed_for_guests(): void
    {
        $this->getJson('/api/organizations')->assertUnauthorized();
        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/x/1124715036/'])->assertUnauthorized();
    }

    public function test_spa_routes_are_served_by_single_view(): void
    {
        $this->get('/organizations/5?page=2')->assertOk()->assertSee('<div id="app"></div>', false);
    }

    public function test_bearer_token_is_rejected_with_401_not_500(): void
    {
        // Sanctum ищет токен раньше, чем смотрит на сессию: без таблицы токенов
        // любой сканер получал бы 500 и сыпал стеками в лог.
        $this->getJson('/api/user', ['Authorization' => 'Bearer not-a-real-token'])
            ->assertUnauthorized();
    }

    public function test_login_with_non_string_email_is_validation_error(): void
    {
        // Ограничитель частоты читает поле до валидации, поэтому там может оказаться что угодно.
        $this->postJson('/api/login', ['email' => ['demo@example.com'], 'password' => 'demo12345'])
            ->assertUnprocessable();
    }

    public function test_repeated_seeding_keeps_the_session_alive(): void
    {
        $this->seed();
        $hash = User::where('email', 'demo@example.com')->value('password');

        $this->seed();

        // Новая соль bcrypt на том же пароле выкинула бы все сессии после каждого деплоя.
        $this->assertSame($hash, User::where('email', 'demo@example.com')->value('password'));
    }
}
