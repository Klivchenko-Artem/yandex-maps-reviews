<?php

namespace App\Providers;

use App\Services\Sync\OrganizationSyncService;
use App\Services\YandexMaps\OrganizationPageParser;
use App\Services\YandexMaps\RequestSigner;
use App\Services\YandexMaps\RequestThrottle;
use App\Services\YandexMaps\ReviewsPageParser;
use App\Services\YandexMaps\YandexMapsClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RequestThrottle::class, fn (Application $app) => new RequestThrottle(
            cache: $app['cache.store'],
            minIntervalMs: (int) config('yandex.min_interval_ms'),
            jitterMs: (int) config('yandex.jitter_ms'),
            blockCooldown: (int) config('yandex.block_cooldown'),
        ));

        $this->app->bind(ReviewsPageParser::class, fn () => new ReviewsPageParser((int) config('yandex.max_pages')));

        // Не singleton намеренно: каждый клиент это отдельная сессия со своими куками, UA и прокси.
        $this->app->bind(YandexMapsClient::class, fn (Application $app) => new YandexMapsClient(
            signer: $app->make(RequestSigner::class),
            throttle: $app->make(RequestThrottle::class),
            pageParser: $app->make(OrganizationPageParser::class),
            reviewsParser: $app->make(ReviewsPageParser::class),
            config: config('yandex'),
        ));

        $this->app->bind(OrganizationSyncService::class, fn (Application $app) => new OrganizationSyncService(
            client: $app->make(YandexMapsClient::class),
            pageSize: (int) config('yandex.page_size'),
            maxPages: (int) config('yandex.max_pages'),
        ));
    }

    public function boot(): void
    {
        // Организация ищется только среди своих: чужой id отвечает 404, а не 403,
        // чтобы по ответам нельзя было перебрать, какие id существуют.
        Route::bind('organization', function (string $value) {
            $user = request()->user();
            abort_if($user === null || ! ctype_digit($value), 404);

            return $user->organizations()->findOrFail((int) $value);
        });

        // Лимитер работает до валидации, поэтому в поле может прийти что угодно, хоть массив.
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');

            return Limit::perMinute(5)->by(mb_strtolower(is_string($email) ? $email : '').'|'.$request->ip());
        });
    }
}
