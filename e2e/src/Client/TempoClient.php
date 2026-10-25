<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webmozart\Assert\Assert;

final class TempoClient
{
    private const int OK = 200;

    private readonly HttpClientInterface $http;

    /** Why the last lookup found no trace, for the timeout message. */
    private string $lastMiss = 'no lookup yet';

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
     * @return TraceTree|null null until Tempo has the trace (404, Tempo not ready, a transport error)
     */
    public function trace(string $traceId): ?TraceTree
    {
        try {
            $response = $this->http->request('GET', '/api/v2/traces/' . $traceId);
            $status = $response->getStatusCode();
            if ($status !== self::OK) {
                $this->lastMiss = sprintf('HTTP %d', $status);

                return null;
            }

            $tree = TraceTree::fromTempo($response->toArray());
        } catch (ExceptionInterface $exception) {
            $this->lastMiss = $exception->getMessage();

            return null;
        }

        $this->lastMiss = 'no spans';

        return $tree->spans === [] ? null : $tree;
    }

    public function lastMiss(): string
    {
        return $this->lastMiss;
    }
}
