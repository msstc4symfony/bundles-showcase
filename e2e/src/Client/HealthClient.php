<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the healthcheck bundle's text output; ?_format=json is not honoured by its controller.
 */
final readonly class HealthClient
{
    private HttpClientInterface $http;

    public function __construct()
    {
        $this->http = HttpClient::create(['timeout' => 10]);
    }

    public function readiness(string $service): HealthReport
    {
        return $this->probe($service, 'readiness');
    }

    public function liveliness(string $service): HealthReport
    {
        return $this->probe($service, 'liveliness');
    }

    private function probe(string $service, string $kind): HealthReport
    {
        try {
            $response = $this->http->request('GET', sprintf('http://%s:8080/_/healthcheck/%s', $service, $kind));

            return new HealthReport($response->getStatusCode(), $response->getContent(false));
        } catch (TransportExceptionInterface $exception) {
            return new HealthReport(0, $exception->getMessage());
        }
    }
}
