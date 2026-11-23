<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webmozart\Assert\Assert;

/**
 * The webhook receiver behind slo-page and slo-ticket (docker/alert-recorder).
 */
final readonly class AlertRecorderClient
{
    private HttpClientInterface $http;

    public function __construct(?string $baseUri = null)
    {
        $baseUri ??= getenv('ALERT_RECORDER_URL');
        Assert::stringNotEmpty($baseUri, 'ALERT_RECORDER_URL is not set.');
        $this->http = HttpClient::createForBaseUri($baseUri, ['timeout' => 10]);
    }

    /**
     * @return list<RecordedNotification> oldest first
     */
    public function notifications(): array
    {
        $notifications = [];
        foreach (WireList::ofArrays($this->http->request('GET', '/')->toArray()) as $record) {
            $receiver = $record['receiver'] ?? null;
            Assert::string($receiver);
            $payload = $record['payload'] ?? null;
            Assert::isArray($payload);

            $notified = [];
            foreach (WireList::ofArrays($payload['alerts'] ?? null) as $alert) {
                $status = $alert['status'] ?? null;
                Assert::oneOf($status, ['firing', 'resolved']);
                $notified[] = new NotifiedAlert($status, AlertLabels::parse($alert['labels'] ?? null));
            }

            $notifications[] = new RecordedNotification($receiver, $notified);
        }

        return $notifications;
    }
}
