<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\OrdersStub;
use Override;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @phpstan-import-type RequestOptions from OrdersStub
 */
final class BaggageProbeControllerTest extends WebTestCase
{
    #[Override]
    protected function tearDown(): void
    {
        // Back to phpunit.dist.xml's empty value: unset would let a real BAGGAGE_ECHO_URL through getenv().
        $_SERVER['BAGGAGE_ECHO_URL'] = '';
        unset($_ENV['BAGGAGE_ECHO_URL']);
        parent::tearDown();
    }

    public function testIsOffWithoutAnEcho(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_probe/baggage');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSendsTheBaggageToOrdersOnly(): void
    {
        $_SERVER['BAGGAGE_ECHO_URL'] = $_ENV['BAGGAGE_ECHO_URL'] = 'http://echo.test/';
        $client = self::createClient();
        $baggage = [];
        $stub = self::getContainer()->get(OrdersStub::class);
        self::assertInstanceOf(OrdersStub::class, $stub);
        $stub->respondWith(
            /** @param RequestOptions $options */
            static function (string $method, string $url, array $options) use (&$baggage): MockResponse {
                $baggage[$method . ' ' . $url] = $options['normalized_headers']['baggage'] ?? [];

                // The bundle, not the transport, follows a redirect from a host that got its baggage.
                if ($url !== 'http://orders.test/_probe/redirect') {
                    return new MockResponse('echo');
                }

                return new MockResponse('', [
                    'http_code' => 307,
                    'response_headers' => ['Location: http://echo.test/via-orders'],
                ]);
            },
        );

        $client->request('GET', '/_probe/baggage', server: ['HTTP_BAGGAGE' => 'tenant=7']);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['direct' => 200, 'via_orders' => 200],
            json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame([
            'GET http://echo.test/direct' => [],
            'GET http://orders.test/_probe/redirect' => ['baggage: msstc4symfony.caller=showcase%3Aunknown,tenant=7'],
            'GET http://echo.test/via-orders' => [],
        ], $baggage);
    }
}
