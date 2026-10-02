<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\OrdersStub;
use Closure;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

final class OrdersControllerTest extends WebTestCase
{
    private const string CREATED = '{"id":"0190a5b2-0000-7000-8000-000000000000","status":"pending","amount":5000}';

    public function testTheSameIdempotencyKeyCreatesOneOrder(): void
    {
        $browser = $this->browserWithOrders(static fn (): MockResponse => new MockResponse(self::CREATED, ['http_code' => 201]));

        $key = Uuid::v7()->toRfc4122();

        $this->postOrder($browser, $key);
        $first = (string) $browser->getResponse()->getContent();
        $this->postOrder($browser, $key);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, $browser->getResponse()->getContent());
        self::assertSame(self::CREATED, $first);
        self::assertSame(1, $this->orders()->calls);
    }

    public function testReusingAKeyForAnotherBodyIsUnprocessable(): void
    {
        $browser = $this->browserWithOrders(static fn (): MockResponse => new MockResponse(self::CREATED, ['http_code' => 201]));
        $key = Uuid::v7()->toRfc4122();

        $this->postOrder($browser, $key);
        $this->postOrder($browser, $key, '{"amount":6000}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('{"error":"idempotency_key_reused"}', $browser->getResponse()->getContent());
        self::assertSame(1, $this->orders()->calls);
    }

    public function testRouterErrorsAreJson(): void
    {
        $browser = $this->browserWithOrders(static fn (): MockResponse => new MockResponse('{}'));

        $browser->request('GET', '/orders/not-a-uuid');

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('json', (string) $browser->getResponse()->headers->get('Content-Type'));
        self::assertJson((string) $browser->getResponse()->getContent());
    }

    public function testServiceRoutesKeepTheirOwnFormat(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/_/healthcheck/liveliness');

        self::assertStringContainsString('Result: up', (string) $browser->getResponse()->getContent());
    }

    public function testWithoutAKeyEveryPostReachesOrders(): void
    {
        $browser = $this->browserWithOrders(static fn (): MockResponse => new MockResponse(self::CREATED, ['http_code' => 201]));

        $this->postOrder($browser, null);
        $this->postOrder($browser, null);

        self::assertSame(2, $this->orders()->calls);
    }

    public function testValidationErrorsPassThrough(): void
    {
        $browser = $this->browserWithOrders(static fn (): MockResponse => new MockResponse('{"detail":"amount"}', ['http_code' => 422]));

        $this->postOrder($browser, null);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('{"detail":"amount"}', $browser->getResponse()->getContent());
    }

    public function testAnUnreachableOrdersServiceIsABadGateway(): void
    {
        $browser = $this->browserWithOrders(static fn (): never => throw new TransportException('Connection refused'));

        $this->postOrder($browser, Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(502);
        self::assertSame('{"error":"orders_unavailable"}', $browser->getResponse()->getContent());
    }

    public function testShowMirrorsOrders(): void
    {
        $browser = $this->browserWithOrders(static fn (): MockResponse => new MockResponse('{"error":"order_not_found"}', ['http_code' => 404]));

        $browser->request('GET', '/orders/0190a5b2-0000-7000-8000-000000000000');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"error":"order_not_found"}', $browser->getResponse()->getContent());
    }

    public function testAMalformedIdIsNotForwarded(): void
    {
        $browser = $this->browserWithOrders(static fn (): MockResponse => new MockResponse('{}'));

        $browser->request('GET', '/orders/not-a-uuid');

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->orders()->calls);
    }

    /**
     * @param Closure(string, string): MockResponse $orders
     */
    private function browserWithOrders(Closure $orders): KernelBrowser
    {
        $browser = self::createClient();
        // A reboot between requests would drop the stub answer and the idempotency cache.
        $browser->disableReboot();
        $this->orders()->respondWith($orders);

        return $browser;
    }

    private function orders(): OrdersStub
    {
        $stub = self::getContainer()->get(OrdersStub::class);
        self::assertInstanceOf(OrdersStub::class, $stub);

        return $stub;
    }

    private function postOrder(KernelBrowser $browser, ?string $idempotencyKey, string $body = '{"amount":5000}'): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($idempotencyKey !== null) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        $browser->request('POST', '/orders', server: $headers, content: $body);
    }
}
