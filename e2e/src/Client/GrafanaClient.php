<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webmozart\Assert\Assert;

final readonly class GrafanaClient
{
    private HttpClientInterface $http;

    public function __construct(?string $baseUri = null)
    {
        $baseUri ??= getenv('GRAFANA_URL');
        Assert::stringNotEmpty($baseUri, 'GRAFANA_URL is not set.');
        $this->http = HttpClient::createForBaseUri($baseUri, ['timeout' => 10]);
    }

    /**
     * @return list<array{uid: string, title: string, folderTitle: string}> folderTitle is "" outside a folder
     */
    public function dashboards(string $tag): array
    {
        $found = $this->http->request('GET', '/api/search', ['query' => ['type' => 'dash-db', 'tag' => $tag]])->toArray();
        Assert::isList($found);

        $dashboards = [];
        foreach ($found as $dashboard) {
            Assert::isArray($dashboard);
            $uid = $dashboard['uid'] ?? null;
            $title = $dashboard['title'] ?? null;
            $folderTitle = $dashboard['folderTitle'] ?? '';
            Assert::string($uid);
            Assert::string($title);
            Assert::string($folderTitle);
            $dashboards[] = ['uid' => $uid, 'title' => $title, 'folderTitle' => $folderTitle];
        }

        return $dashboards;
    }
}
