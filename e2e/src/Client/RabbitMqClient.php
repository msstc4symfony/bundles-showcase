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
        $user = getenv('RABBITMQ_USER');
        $password = getenv('RABBITMQ_PASSWORD');
        $this->http = HttpClient::createForBaseUri($baseUri, [
            'timeout' => 10,
            'auth_basic' => [is_string($user) && $user !== '' ? $user : 'guest', is_string($password) ? $password : 'guest'],
        ]);
    }

    /**
     * True when every queue reports no ready and no unacknowledged message. A queue without statistics yet
     * (just declared) does not count as idle. The management API refreshes these figures every few seconds.
     */
    public function isIdle(): bool
    {
        $queues = $this->http->request('GET', '/api/queues')->toArray();
        foreach ($queues as $queue) {
            Assert::isArray($queue);
            if (($queue['messages_ready'] ?? null) !== 0 || ($queue['messages_unacknowledged'] ?? null) !== 0) {
                return false;
            }
        }

        return true;
    }
}
