<?php

namespace Tests\Unit\YandexMaps;

use App\Services\YandexMaps\RequestSigner;
use PHPUnit\Framework\TestCase;

class RequestSignerTest extends TestCase
{
    /**
     * Эталон посчитан оригинальной функцией из бандла Яндекс.Карт в node,
     * а запрос с этой подписью Яндекс принял и вернул отзывы.
     */
    public function test_matches_signature_computed_by_yandex_frontend(): void
    {
        $params = [
            'sessionId' => '1789457983454844-11131681426281896478-balancer-l7leveler-kubr-yp-sas-83-BAL',
            'ajax' => '1',
            'pageSize' => '50',
            'businessId' => '1124715036',
            'csrfToken' => 'a85e37f3353b44b3b0a54cd008970faf6088f246:1789457983',
            'locale' => 'ru_RU',
            'page' => '2',
            'ranking' => 'by_time',
        ];

        $signer = new RequestSigner;

        $this->assertSame(
            'ajax=1&businessId=1124715036&csrfToken=a85e37f3353b44b3b0a54cd008970faf6088f246%3A1789457983&locale=ru_RU&page=2&pageSize=50&ranking=by_time&sessionId=1789457983454844-11131681426281896478-balancer-l7leveler-kubr-yp-sas-83-BAL',
            $signer->canonicalQuery($params),
        );
        $this->assertSame('3019062724', $signer->sign($params));
    }

    public function test_sorts_keys_case_insensitively_and_encodes_like_rfc3986(): void
    {
        $params = ['b' => 'x y', 'A' => 'привет', 'c' => 1];
        $signer = new RequestSigner;

        $this->assertSame('A=%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82&b=x%20y&c=1', $signer->canonicalQuery($params));
        $this->assertSame('1879293844', $signer->sign($params));
    }
}
