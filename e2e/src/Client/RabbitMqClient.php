<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webmozart\Assert\Assert;

final readonly class RabbitMqClient
{
    private HttpClientInterface $http;

    public function __construct(?string $baseUri = null)
    {
        $baseUri ??= getenv('RABBITMQ_URL');
        Assert::stringNotEmpty($baseUri, 'RABBITMQ_URL is not set.');
        $this->http = HttpClient::createForBaseUri($baseUri, ['timeout' => 10, 'auth_basic' => ['guest', 'guest']]);
    }

    /**
     * True when no queue holds a ready or an unacknowledged message.
     */
    public function isIdle(): bool
    {
        $queues = $this->http->request('GET', '/api/queues')->toArray();
        foreach ($queues as $queue) {
            Assert::isArray($queue);
            if (($queue['messages'] ?? 0) !== 0) {
                return false;
            }
        }

        return true;
    }
}
