<?php

namespace App\Services\YandexMaps;

use App\Services\YandexMaps\Data\OrganizationPage;
use App\Services\YandexMaps\Data\ReviewsPage;
use App\Services\YandexMaps\Exceptions\InvalidOrganizationLink;
use App\Services\YandexMaps\Exceptions\OrganizationNotFound;
use App\Services\YandexMaps\Exceptions\SourceBlocked;
use App\Services\YandexMaps\Exceptions\SourceChanged;
use App\Services\YandexMaps\Exceptions\SourceUnavailable;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Транспорт к Яндекс.Картам: HTTP, куки, подпись, троттлинг и перевод
 * HTTP-проблем в доменные исключения. Разбором данных занимаются парсеры.
 *
 * Один экземпляр равен одной «браузерной сессии»: общий cookie jar, один User-Agent
 * и один прокси на всю синхронизацию организации. Яндекс привязывает csrf-токен
 * к кукам, так что менять их посреди обхода страниц нельзя.
 */
class YandexMapsClient
{
    private const CAPTCHA_PATTERN = '#showcaptcha|checkcaptcha|smart-?captcha#i';

    private CookieJar $cookies;

    private string $userAgent;

    private ?string $proxy;

    /**
     * @param  array{base_url: string, timeout: int, page_size: int, user_agents: list<string>, proxies: list<string>}  $config
     */
    public function __construct(
        private readonly RequestSigner $signer,
        private readonly RequestThrottle $throttle,
        private readonly OrganizationPageParser $pageParser,
        private readonly ReviewsPageParser $reviewsParser,
        private readonly array $config,
    ) {
        $this->cookies = new CookieJar;
        $this->userAgent = $config['user_agents'][array_rand($config['user_agents'])];
        $this->proxy = $config['proxies'] === [] ? null : $config['proxies'][array_rand($config['proxies'])];
    }

    /**
     * Разворачивает короткую ссылку /maps/-/XXXX в полную. По редиректу не идём,
     * нужен только заголовок Location.
     */
    public function resolveShortLink(string $url): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->withoutRedirecting()->get($url), 'short_link');

        $location = $response->header('Location');

        // Капчу Яндекс тоже отдаёт редиректом. Без этой проверки она превратилась бы
        // в «не нашли в ссылке организацию», и человек правил бы совершенно верную ссылку.
        if (preg_match(self::CAPTCHA_PATTERN, $location.$response->body()) === 1) {
            throw $this->blocked('капча при разворачивании короткой ссылки');
        }

        if (! $response->redirect() || $location === '') {
            throw new InvalidOrganizationLink('Короткая ссылка не ведёт на карточку организации');
        }

        return str_starts_with($location, '/') ? rtrim($this->config['base_url'], '/').$location : $location;
    }

    public function openOrganization(string $externalId): OrganizationPage
    {
        $url = $this->url("/maps/org/{$externalId}/reviews/");
        $response = $this->send(fn (PendingRequest $http) => $http->accept('text/html')->get($url), 'org_page');

        if ($response->status() === 404) {
            throw OrganizationNotFound::forId($externalId);
        }

        $effectiveUri = (string) ($response->effectiveUri() ?? '');
        if (str_contains($effectiveUri, 'showcaptcha')) {
            throw $this->blocked('капча при открытии карточки');
        }

        try {
            return $this->pageParser->parse($response->body(), $externalId, $this->redirectedId($effectiveUri));
        } catch (SourceBlocked $e) {
            throw $this->blocked($e->getMessage());
        }
    }

    public function fetchReviewsPage(OrganizationPage $page, int $number): ReviewsPage
    {
        // Яндекс изредка отвечает на страницу разовой ошибкой 500 (ловили вживую на 10-й из 12).
        // Перезапрашиваем эту страницу на месте: уронить всю попытку значило бы начать обход заново.
        $retries = (int) ($this->config['page_retries'] ?? 2);
        $delayMs = (int) ($this->config['page_retry_delay_ms'] ?? 3000);

        return retry(
            $retries + 1,
            fn () => $this->fetchReviewsPageOnce($page, $number),
            function (int $attempt, Throwable $e) use ($number, $delayMs) {
                Log::info('Яндекс: повтор страницы отзывов', ['page' => $number, 'try' => $attempt, 'error' => $e->getMessage()]);

                return $delayMs * $attempt;
            },
            fn (Throwable $e) => $e instanceof SourceUnavailable,
        );
    }

    /**
     * Одна попытка без повторов на месте: для канарейки, которая проверяет границы выдачи.
     * За последней страницей Яндекс отвечает ошибкой 500 в теле ответа, это ожидаемо, поэтому null.
     * Сеть, таймаут и капча не молчат: иначе «не достучались» выглядело бы как «страниц больше нет».
     */
    public function probeReviewsPage(OrganizationPage $page, int $number): ?ReviewsPage
    {
        try {
            return $this->fetchReviewsPageOnce($page, $number);
        } catch (SourceUnavailable $e) {
            if (! $e->fromApiBody()) {
                throw $e;
            }

            return null;
        }
    }

    private function fetchReviewsPageOnce(OrganizationPage $page, int $number): ReviewsPage
    {
        $json = $this->requestReviews($page, $number, $page->csrfToken);

        // Если токен протух, API отвечает {"csrfToken": "новый"}, и мы повторяем один раз, как делает фронт карт.
        // Новый токен запоминаем на всю сессию: иначе каждая следующая страница снова ходила бы дважды.
        if ($this->isTokenRefresh($json)) {
            if (! is_string($json['csrfToken'])) {
                throw SourceChanged::because('API отзывов вернул csrfToken не строкой');
            }

            $page->csrfToken = $json['csrfToken'];
            $json = $this->requestReviews($page, $number, $page->csrfToken);

            if ($this->isTokenRefresh($json)) {
                throw SourceChanged::because('API отзывов не принимает csrf-токен даже после обновления');
            }
        }

        try {
            return $this->reviewsParser->parse($json, $number);
        } catch (SourceBlocked $e) {
            throw $this->blocked($e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function isTokenRefresh(array $json): bool
    {
        return isset($json['csrfToken']) && ! isset($json['data']);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestReviews(OrganizationPage $page, int $number, string $csrfToken): array
    {
        $params = [
            'ajax' => '1',
            'businessId' => $page->organization->externalId,
            'csrfToken' => $csrfToken,
            'locale' => 'ru_RU',
            'page' => (string) $number,
            'pageSize' => (string) $this->config['page_size'],
            'ranking' => 'by_time',
            'sessionId' => $page->sessionId,
        ];
        $params['s'] = $this->signer->sign($params);

        $referer = $this->url("/maps/org/{$page->organization->externalId}/reviews/");
        $url = $this->url('/maps/api/business/fetchReviews').'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $response = $this->send(
            fn (PendingRequest $http) => $http->accept('application/json')->withHeaders(['Referer' => $referer])->get($url),
            'reviews_page',
        );

        // Так Яндекс отвечает на неверную подпись или неизвестные параметры (проверено вживую):
        // голый 400 без JSON. Повтор не поможет, поменялся сам протокол.
        if ($response->status() === 400) {
            throw SourceChanged::because('API отзывов отклонил запрос (HTTP 400): вероятно, изменился алгоритм подписи или набор параметров', [
                'body_head' => mb_substr($response->body(), 0, 300),
            ]);
        }

        $json = json_decode($response->body(), true);
        if (! is_array($json)) {
            if (preg_match(self::CAPTCHA_PATTERN, $response->body()) === 1) {
                throw $this->blocked('капча вместо JSON отзывов');
            }

            throw SourceChanged::because("fetchReviews вернул не JSON (HTTP {$response->status()})", [
                'body_head' => mb_substr($response->body(), 0, 300),
            ]);
        }

        return $json;
    }

    /** Яндекс склеивает дубли карточек и уводит редиректом на новый id. */
    private function redirectedId(string $effectiveUri): ?string
    {
        return preg_match('#/org/(?:[^/]+/)?(\d{5,20})#', $effectiveUri, $m) === 1 ? $m[1] : null;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call, string $kind): Response
    {
        $this->throttle->beforeRequest($this->scope());

        $http = Http::timeout($this->config['timeout'])
            ->withUserAgent($this->userAgent)
            ->withHeaders(['Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.5'])
            ->withOptions(array_filter([
                'cookies' => $this->cookies,
                'proxy' => $this->proxy,
            ]));

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            throw new SourceUnavailable("Яндекс недоступен ({$kind}): {$e->getMessage()}", previous: $e);
        }

        if ($response->status() === 429) {
            throw $this->blocked("HTTP 429 ({$kind})");
        }

        if ($response->serverError()) {
            throw new SourceUnavailable("Яндекс ответил HTTP {$response->status()} ({$kind})");
        }

        if ($response->status() === 403) {
            throw $this->blocked("HTTP 403 ({$kind})");
        }

        return $response;
    }

    private function blocked(string $reason): SourceBlocked
    {
        $scope = $this->scope();
        $this->throttle->reportBlocked($reason, $scope);

        Log::warning('Яндекс: блокировка', ['reason' => $reason, 'proxy' => $this->proxy !== null, 'user_agent' => $this->userAgent]);

        return new SourceBlocked(
            "Яндекс временно ограничил запросы: {$reason}",
            $this->throttle->secondsUntilUnblocked($scope),
        );
    }

    private function url(string $path): string
    {
        return rtrim($this->config['base_url'], '/').$path;
    }

    /**
     * Пауза после бана своя у каждого выхода в интернет: один забаненный прокси
     * не должен останавливать задачи, которые ходят через другие.
     */
    private function scope(): string
    {
        return $this->proxy === null ? RequestThrottle::DIRECT : 'proxy:'.substr(sha1($this->proxy), 0, 12);
    }
}
