<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The gateway's baggage probe follows this redirect to the baggage echo, a host the gateway does not send baggage
 * to. Off unless BAGGAGE_ECHO_URL is set.
 */
#[AsController]
final readonly class RedirectProbeController
{
    private ?string $echoUrl;

    public function __construct(
        private LoggerInterface $logger,
        #[Autowire(env: 'default::BAGGAGE_ECHO_URL')]
        ?string $echoUrl,
    ) {
        $this->echoUrl = $echoUrl === null ? null : rtrim($echoUrl, '/');
    }

    #[Route('/_probe/redirect', name: 'orders_redirect_probe', methods: ['GET'])]
    public function __invoke(): RedirectResponse
    {
        if ($this->echoUrl === null) {
            throw new NotFoundHttpException();
        }

        $this->logger->info('Redirecting the baggage probe to the baggage echo');

        return new RedirectResponse($this->echoUrl . '/via-orders', RedirectResponse::HTTP_TEMPORARY_REDIRECT);
    }
}
