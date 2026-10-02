<?php

declare(strict_types=1);

namespace App\Controller;

use App\Idempotency\IdempotencyKeyReused;
use App\Idempotency\IdempotencyStore;
use App\Idempotency\IdempotencyUnavailable;
use App\Orders\OrdersClient;
use App\Orders\OrdersResponse;
use App\Orders\OrdersUnavailable;
use Closure;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[AsController]
final readonly class OrdersController
{
    public function __construct(
        private OrdersClient $orders,
        private IdempotencyStore $idempotency,
    ) {
    }

    #[Route('/orders', name: 'gateway_create_order', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $payload = $request->getContent();
        $key = $request->headers->get('Idempotency-Key', '');

        return $this->mirror(fn (): OrdersResponse => $key === ''
            ? $this->orders->create($payload)
            : OrdersResponse::fromJson($this->idempotency->remember(
                $key,
                $payload,
                fn (): string => $this->orders->create($payload)->toJson(),
            )));
    }

    #[Route('/orders/{id}', name: 'gateway_show_order', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id): Response
    {
        return $this->mirror(fn (): OrdersResponse => $this->orders->show($id));
    }

    /**
     * @param Closure(): OrdersResponse $call
     */
    private function mirror(Closure $call): Response
    {
        try {
            $answer = $call();
        } catch (OrdersUnavailable) {
            return new JsonResponse(['error' => 'orders_unavailable'], Response::HTTP_BAD_GATEWAY);
        } catch (IdempotencyKeyReused) {
            return new JsonResponse(['error' => 'idempotency_key_reused'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (IdempotencyUnavailable) {
            return new JsonResponse(['error' => 'idempotency_unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new Response($answer->body, $answer->status, ['Content-Type' => 'application/json']);
    }
}
