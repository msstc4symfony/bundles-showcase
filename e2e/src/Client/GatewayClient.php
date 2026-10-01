<?php

declare(strict_types=1);

namespace App\E2e\Client;

use JsonException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Webmozart\Assert\Assert;

final readonly class GatewayClient
{
    private HttpClientInterface $http;

    public function __construct(?string $baseUri = null)
    {
        $baseUri ??= getenv('GATEWAY_URL');
        Assert::stringNotEmpty($baseUri, 'GATEWAY_URL is not set.');
        $this->http = HttpClient::createForBaseUri($baseUri, ['timeout' => 10]);
    }

    /**
     * @param array<string, string> $headers
     */
    public function createOrder(int $amount, ?string $idempotencyKey = null, array $headers = []): GatewayResponse
    {
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->read($this->http->request('POST', '/orders', ['json' => ['amount' => $amount], 'headers' => $headers]));
    }

    /**
     * Requests are sent before any response is read, so HttpClient keeps them in flight together.
     *
     * @param positive-int $count
     *
     * @return list<GatewayResponse>
     */
    public function createOrdersConcurrently(int $count, int $amount, string $idempotencyKey): array
    {
        $pending = [];
        for ($i = 0; $i < $count; $i++) {
            $pending[] = $this->http->request('POST', '/orders', [
                'json' => ['amount' => $amount],
                'headers' => ['Idempotency-Key' => $idempotencyKey],
            ]);
        }

        return array_map($this->read(...), $pending);
    }

    /**
     * @param array<string, string> $headers
     */
    public function showOrder(string $id, array $headers = []): GatewayResponse
    {
        return $this->read($this->http->request('GET', '/orders/' . $id, ['headers' => $headers]));
    }

    private function read(ResponseInterface $response): GatewayResponse
    {
        $content = $response->getContent(false);

        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        $body = [];
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                $body[(string) $key] = $value;
            }
        }

        return new GatewayResponse($response->getStatusCode(), $body, $response->getHeaders(false));
    }
}
