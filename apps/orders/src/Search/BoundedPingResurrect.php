<?php

declare(strict_types=1);

namespace App\Search;

use Elastic\Transport\NodePool\Node;
use Elastic\Transport\NodePool\Resurrect\ResurrectInterface;
use Override;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Throwable;

/**
 * elastic-transport's ElasticsearchResurrect pings a dead node with an HTTP client that has no time limit. In a
 * long-running worker that client keeps a keep-alive connection and curl's cached address of a stopped
 * container, so every readiness probe and every indexing call waited 3 to 38 s while Elasticsearch was down.
 */
final readonly class BoundedPingResurrect implements ResurrectInterface
{
    private const float PING_SECONDS = 1.0;

    public function __construct(
        private Psr18Client $client,
    ) {
    }

    public static function create(): self
    {
        return new self(new Psr18Client(HttpClient::create(['max_duration' => self::PING_SECONDS])));
    }

    #[Override]
    public function ping(Node $node): bool
    {
        try {
            $response = $this->client->sendRequest($this->client->createRequest('HEAD', $node->getUri()));
        } catch (Throwable) {
            return false;
        }

        return $response->getStatusCode() === 200;
    }
}
