<?php

declare(strict_types=1);

namespace App\Orders;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OrdersClient
{
    public function __construct(
        private HttpClientInterface $ordersClient,
    ) {
    }

    /**
     * @throws OrdersUnavailable
     */
    public function create(string $payload): OrdersResponse
    {
        return $this->send('POST', '/orders', [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => $payload,
        ]);
    }

    /**
     * @throws OrdersUnavailable
     */
    public function show(string $id): OrdersResponse
    {
        return $this->send('GET', '/orders/' . $id, ['headers' => ['Accept' => 'application/json']]);
    }

    /**
     * @param array{headers?: array<string, string>, body?: string} $options
     *
     * @throws OrdersUnavailable
     */
    private function send(string $method, string $path, array $options): OrdersResponse
    {
        try {
            $response = $this->ordersClient->request($method, $path, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            throw new OrdersUnavailable('Orders service is unreachable.', $exception->getCode(), previous: $exception);
        }

        // A 5xx is a failure to answer, not an answer to mirror (or to remember for an idempotency key).
        if ($status >= 500) {
            throw new OrdersUnavailable(sprintf('Orders service failed with HTTP %d.', $status));
        }

        return new OrdersResponse($status, $body);
    }
}
