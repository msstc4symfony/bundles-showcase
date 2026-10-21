<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Closure;
use LogicException;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Stands in for the orders service: every HttpClient of the test kernel answers through it
 * (framework.http_client.mock_response_factory).
 *
 * @phpstan-type RequestOptions array{normalized_headers?: array<string, list<string>>, ...}
 */
final class OrdersStub
{
    /** @var (Closure(string, string, RequestOptions): MockResponse)|null */
    private ?Closure $respond = null;

    public int $calls = 0;

    /**
     * @param Closure(string, string, RequestOptions): MockResponse $respond receives the method, the URL and the options
     */
    public function respondWith(Closure $respond): void
    {
        $this->respond = $respond;
        $this->calls = 0;
    }

    /**
     * @param RequestOptions $options
     */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        if (!$this->respond instanceof Closure) {
            throw new LogicException('OrdersStub has no configured answer.');
        }

        $this->calls++;

        return ($this->respond)($method, $url, $options);
    }
}
