<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webmozart\Assert\Assert;

final readonly class TempoClient
{
    private const int NOT_FOUND = 404;

    private HttpClientInterface $http;

    public function __construct(?string $baseUri = null)
    {
        $baseUri ??= getenv('TEMPO_URL');
        Assert::stringNotEmpty($baseUri, 'TEMPO_URL is not set.');
        $this->http = HttpClient::createForBaseUri($baseUri, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
    }

    /**
     * @return TraceTree|null null until Tempo has the trace
     */
    public function trace(string $traceId): ?TraceTree
    {
        $response = $this->http->request('GET', '/api/v2/traces/' . $traceId);
        if ($response->getStatusCode() === self::NOT_FOUND) {
            return null;
        }

        $tree = TraceTree::fromTempo($response->toArray());

        return $tree->spans === [] ? null : $tree;
    }
}
