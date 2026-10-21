<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lets e2e check which hosts get this gateway's baggage: the baggage echo directly, and through a redirect from
 * orders. Off unless BAGGAGE_ECHO_URL is set.
 */
#[AsController]
final readonly class BaggageProbeController
{
    private ?string $echoUrl;

    public function __construct(
        private HttpClientInterface $httpClient,
        private HttpClientInterface $ordersClient,
        #[Autowire(env: 'default::BAGGAGE_ECHO_URL')]
        ?string $echoUrl,
    ) {
        $this->echoUrl = $echoUrl === null ? null : rtrim($echoUrl, '/');
    }

    #[Route('/_probe/baggage', name: 'gateway_baggage_probe', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        if ($this->echoUrl === null) {
            throw new NotFoundHttpException();
        }

        $direct = $this->httpClient->request('GET', $this->echoUrl . '/direct', ['timeout' => 5, 'max_duration' => 10]);
        $viaOrders = $this->ordersClient->request('GET', '/_probe/redirect');

        return new JsonResponse(['direct' => $direct->getStatusCode(), 'via_orders' => $viaOrders->getStatusCode()]);
    }
}
