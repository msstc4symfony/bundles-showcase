<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Order\OrderView;
use App\Repository\OrderRepository;
use Msstc4Symfony\SloBundle\Application\Attribute\IgnoreMonitoring;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[AsController]
final readonly class ShowOrderController
{
    public function __construct(
        private OrderRepository $orders,
    ) {
    }

    #[Route('/orders/{id}', name: 'orders_show', requirements: ['id' => Requirement::UUID], methods: ['GET'], format: 'json')]
    #[IgnoreMonitoring(reason: 'Read path; the showcase declares SLOs on writes')]
    public function __invoke(string $id): JsonResponse
    {
        $order = $this->orders->find($id);
        if (!$order instanceof Order) {
            return new JsonResponse(['error' => 'not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse(OrderView::of($order));
    }
}
