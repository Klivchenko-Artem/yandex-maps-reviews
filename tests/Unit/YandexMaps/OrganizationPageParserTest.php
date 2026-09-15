<?php

namespace Tests\Unit\YandexMaps;

use App\Services\YandexMaps\Exceptions\OrganizationNotFound;
use App\Services\YandexMaps\Exceptions\SourceBlocked;
use App\Services\YandexMaps\Exceptions\SourceChanged;
use App\Services\YandexMaps\OrganizationPageParser;
use PHPUnit\Framework\TestCase;

class OrganizationPageParserTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/yandex/org_page.html');
    }

    /** Подменяет состояние страницы, сохраняя обёртку HTML как у Яндекса. */
    private function withState(callable $mutate): string
    {
        $html = $this->fixture();
        preg_match('#class="state-view">(.*?)</script>#s', $html, $m);
        $state = $mutate(json_decode($m[1], true));

        return str_replace($m[1], json_encode($state, JSON_UNESCAPED_UNICODE), $html);
    }

    public function test_parses_organization_and_session_from_state(): void
    {
        $page = (new OrganizationPageParser)->parse($this->fixture(), '1000000001');

        $this->assertSame('1000000001', $page->organization->externalId);
        $this->assertSame('Кофейня «Тестовая»', $page->organization->name);
        $this->assertSame('Таганрог, Петровская улица, 1', $page->organization->address);
        // float32 из ответа округляется до того, что видно на карточке.
        $this->assertSame(4.7, $page->organization->rating);
        $this->assertSame(1234, $page->organization->ratingsCount);
        $this->assertSame(3, $page->organization->reviewsCount);
        $this->assertSame('fixturecsrf0000000000000000000000000000:1789457983', $page->csrfToken);
        $this->assertSame('fixture-session-id', $page->sessionId);
    }

    public function test_organization_without_ratings_reports_unknown_counts(): void
    {
        $html = $this->withState(function (array $state) {
            unset($state['stack'][0]['results']['items'][0]['ratingData']);

            return $state;
        });

        $organization = (new OrganizationPageParser)->parse($html, '1000000001')->organization;

        // Не ноль, а «неизвестно»: ноль затёр бы верные цифры, если блок не исчез, а переименован.
        $this->assertFalse($organization->hasRatingData());
        $this->assertNull($organization->rating);
        $this->assertNull($organization->ratingsCount);
        $this->assertNull($organization->reviewsCount);
    }

    public function test_empty_results_mean_organization_not_found(): void
    {
        $html = $this->withState(function (array $state) {
            $state['stack'][0]['results']['items'] = [];

            return $state;
        });

        $this->expectException(OrganizationNotFound::class);

        (new OrganizationPageParser)->parse($html, '1000000001');
    }

    public function test_card_under_another_id_is_accepted_only_after_redirect(): void
    {
        // Яндекс склеил дубли и увёл редиректом на новый id: только это и позволяет
        // считать чужую по номеру карточку нашей.
        $page = (new OrganizationPageParser)->parse($this->fixture(), '999999999', '1000000001');

        $this->assertSame('1000000001', $page->organization->externalId);
    }

    public function test_card_under_another_id_without_redirect_is_not_ours(): void
    {
        $this->expectException(OrganizationNotFound::class);

        (new OrganizationPageParser)->parse($this->fixture(), '999999999');
    }

    public function test_results_without_items_are_reported_as_source_change(): void
    {
        // Пустой список это честное «организации нет», а вот исчезнувший список значит,
        // что мы разучились читать выдачу.
        $html = $this->withState(function (array $state) {
            $state['stack'][0]['results']['cards'] = $state['stack'][0]['results']['items'];
            unset($state['stack'][0]['results']['items']);

            return $state;
        });

        $this->expectException(SourceChanged::class);
        $this->expectExceptionMessage('results.items');

        (new OrganizationPageParser)->parse($html, '1000000001');
    }

    public function test_items_without_business_type_are_reported_as_source_change(): void
    {
        $html = $this->withState(function (array $state) {
            $state['stack'][0]['results']['items'][0]['type'] = 'organization';

            return $state;
        });

        $this->expectException(SourceChanged::class);
        $this->expectExceptionMessage('type=business');

        (new OrganizationPageParser)->parse($html, '1000000001');
    }

    public function test_huge_state_is_parsed_without_regexp_limits(): void
    {
        // Регулярка с ленивым `.*?` на таком объёме упирается в pcre.backtrack_limit
        // и возвращает false, то есть «состояния нет» вместо разбора.
        $html = $this->withState(function (array $state) {
            $state['padding'] = str_repeat('очень длинное описание организации. ', 40000);

            return $state;
        });

        $this->assertGreaterThan(1_000_000, strlen($html));
        $this->assertSame('1000000001', (new OrganizationPageParser)->parse($html, '1000000001')->organization->externalId);
    }

    public function test_list_of_other_places_is_not_mistaken_for_our_organization(): void
    {
        // Вместо удалённой организации Яндекс показывает похожие места, и ни одна из них не наша.
        $html = $this->withState(function (array $state) {
            $other = $state['stack'][0]['results']['items'][0];
            $other['id'] = '2000000002';
            $other['title'] = 'Соседняя кофейня';
            $state['stack'][0]['results']['items'][] = $other;

            return $state;
        });

        $this->expectException(OrganizationNotFound::class);

        (new OrganizationPageParser)->parse($html, '999999999');
    }

    public function test_renamed_rating_fields_are_reported_as_source_change(): void
    {
        $html = $this->withState(function (array $state) {
            $state['stack'][0]['results']['items'][0]['ratingData'] = ['score' => 4.7, 'votes' => 1234];

            return $state;
        });

        $this->expectException(SourceChanged::class);
        $this->expectExceptionMessage('ratingData');

        (new OrganizationPageParser)->parse($html, '1000000001');
    }

    public function test_missing_csrf_token_is_reported_as_source_change(): void
    {
        $html = $this->withState(function (array $state) {
            unset($state['config']['csrfToken']);

            return $state;
        });

        $this->expectException(SourceChanged::class);

        (new OrganizationPageParser)->parse($html, '1000000001');
    }

    public function test_page_without_state_is_reported_as_source_change(): void
    {
        $this->expectException(SourceChanged::class);
        $this->expectExceptionMessage('state-view');

        (new OrganizationPageParser)->parse('<html><body><div class="business-card">новая вёрстка</div></body></html>', '1000000001');
    }

    public function test_empty_body_is_reported_as_source_change(): void
    {
        $this->expectException(SourceChanged::class);

        (new OrganizationPageParser)->parse('', '1000000001');
    }

    public function test_captcha_page_is_reported_as_block(): void
    {
        $this->expectException(SourceBlocked::class);

        (new OrganizationPageParser)->parse('<html><form action="/checkcaptcha?key=abc">Подтвердите, что вы не робот</form></html>', '1000000001');
    }
}
