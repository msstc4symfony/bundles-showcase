<?php

declare(strict_types=1);

namespace App\Tests\Unit\Orders;

use App\Orders\OrdersClient;
use App\Orders\OrdersUnavailable;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OrdersClientTest extends TestCase
{
    #[TestWith([201, '{"id":"a","status":"pending","amount":5000}'])]
    #[TestWith([422, '{"detail":"amount: This value should be positive."}'])]
    public function testCreateForwardsThePayloadAndMirrorsTheAnswer(int $status, string $body): void
    {
        $upstream = new MockResponse($body, ['http_code' => $status]);
        $client = new OrdersClient(new MockHttpClient($upstream, 'http://orders.test'));

        $response = $client->create('{"amount":5000}');

        self::assertSame([$status, $body], [$response->status, $response->body]);
        self::assertSame('POST', $upstream->getRequestMethod());
        self::assertSame('http://orders.test/orders', $upstream->getRequestUrl());
        self::assertSame('{"amount":5000}', $upstream->getRequestOptions()['body']);
        self::assertContains('Content-Type: application/json', $upstream->getRequestOptions()['headers']);
        self::assertContains('Accept: application/json', $upstream->getRequestOptions()['headers']);
    }

    #[TestWith([200, '{"id":"a","status":"paid","amount":5000}'])]
    #[TestWith([404, '{"error":"order_not_found"}'])]
    public function testShowMirrorsTheAnswer(int $status, string $body): void
    {
        $upstream = new MockResponse($body, ['http_code' => $status]);
        $client = new OrdersClient(new MockHttpClient($upstream, 'http://orders.test'));

        $response = $client->show('0190a5b2-0000-7000-8000-000000000000');

        self::assertSame([$status, $body], [$response->status, $response->body]);
        self::assertSame('GET', $upstream->getRequestMethod());
        self::assertSame('http://orders.test/orders/0190a5b2-0000-7000-8000-000000000000', $upstream->getRequestUrl());
        self::assertContains('Accept: application/json', $upstream->getRequestOptions()['headers']);
    }

    public function testAnUnreachableUpstreamIsUnavailable(): void
    {
        $client = new OrdersClient(new MockHttpClient(static fn (): never => throw new TransportException('Connection refused'), 'http://orders.test'));

        $this->expectException(OrdersUnavailable::class);

        $client->show('0190a5b2-0000-7000-8000-000000000000');
    }

    public function testAnUpstreamServerErrorIsUnavailable(): void
    {
        $client = new OrdersClient(new MockHttpClient(new MockResponse('oops', ['http_code' => 500]), 'http://orders.test'));

        $this->expectException(OrdersUnavailable::class);

        $client->create('{"amount":5000}');
    }
}
