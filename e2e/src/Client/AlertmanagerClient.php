<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webmozart\Assert\Assert;

final readonly class AlertmanagerClient
{
    private HttpClientInterface $http;

    public function __construct(?string $baseUri = null)
    {
        $baseUri ??= getenv('ALERTMANAGER_URL');
        Assert::stringNotEmpty($baseUri, 'ALERTMANAGER_URL is not set.');
        $this->http = HttpClient::createForBaseUri($baseUri, ['timeout' => 10]);
    }

    /**
     * Inhibited and silenced alerts included.
     *
     * @param non-empty-array<non-empty-string, string> $labels equality matchers
     *
     * @return list<ManagedAlert>
     */
    public function alerts(array $labels): array
    {
        // Alertmanager reads a repeated "filter" parameter; HttpClient would encode an array as filter[0]=...
        $filters = [];
        foreach ($labels as $name => $value) {
            $filters[] = 'filter=' . rawurlencode(sprintf('%s="%s"', $name, addcslashes($value, '"\\')));
        }

        $alerts = [];
        foreach (WireList::ofArrays($this->http->request('GET', '/api/v2/alerts?' . implode('&', $filters))->toArray()) as $alert) {
            $fingerprint = $alert['fingerprint'] ?? null;
            Assert::stringNotEmpty($fingerprint);
            $status = $alert['status'] ?? null;
            Assert::isArray($status);
            $state = $status['state'] ?? null;
            Assert::oneOf($state, ['active', 'suppressed', 'unprocessed']);
            $inhibitedBy = $status['inhibitedBy'] ?? null;
            Assert::isList($inhibitedBy);
            Assert::allString($inhibitedBy);
            $alerts[] = new ManagedAlert($fingerprint, AlertLabels::parse($alert['labels'] ?? null), $state, $inhibitedBy);
        }

        return $alerts;
    }
}
