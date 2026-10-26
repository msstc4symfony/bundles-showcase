<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webmozart\Assert\Assert;

final readonly class TempoClient
{
    private const int OK = 200;

    private const int NOT_FOUND = 404;

    private HttpClientInterface $http;

    public function __construct(?string $baseUri = null)
    {
        $baseUri ??= getenv('TEMPO_URL');
        Assert::stringNotEmpty($baseUri, 'TEMPO_URL is not set.');
        $this->http = HttpClient::createForBaseUri($baseUri, [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    /**
     * No tree until Tempo has the trace: 404, Tempo not ready yet, a transport error. Tempo answers an unknown trace
     * id with 200 and an empty trace; that and a 404 set notFound.
     */
    public function trace(string $traceId): TraceLookup
    {
        try {
            $response = $this->http->request('GET', '/api/v2/traces/' . $traceId);
            $status = $response->getStatusCode();
            if ($status !== self::OK) {
                return new TraceLookup(null, sprintf('HTTP %d', $status), $status === self::NOT_FOUND);
            }

            $tree = TraceTree::fromTempo($response->toArray());
        } catch (ExceptionInterface $exception) {
            return new TraceLookup(null, $exception->getMessage());
        }

        return $tree->spans === []
            ? new TraceLookup(null, 'no spans', true)
            : new TraceLookup($tree, sprintf('spans of %s', implode(', ', $tree->services())));
    }
}
