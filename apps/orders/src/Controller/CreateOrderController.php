<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Message\ProcessPayment;
use App\Order\CreateOrderRequest;
use App\Order\OrderView;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Webmozart\Assert\Assert;

#[AsController]
final readonly class CreateOrderController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    #[Route('/orders', name: 'orders_create', methods: ['POST'], format: 'json')]
    public function __invoke(#[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: 422)] CreateOrderRequest $request): JsonResponse
    {
        // Already validated by the request constraints; the assertion narrows mixed to int.
        Assert::integer($request->amount);
        $order = new Order($request->amount);

        // Flush before dispatch so a database error never publishes a payment for a missing order;
        // a failed AMQP send still rolls the order back.
        $this->entityManager->wrapInTransaction(function () use ($order): void {
            $this->entityManager->persist($order);
            $this->entityManager->flush();

            $this->bus->dispatch(new ProcessPayment($order->id(), $order->amount()));
        });

        return new JsonResponse(OrderView::of($order), JsonResponse::HTTP_CREATED);
    }
}
