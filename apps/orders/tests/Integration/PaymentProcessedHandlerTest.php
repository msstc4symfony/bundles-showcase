<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Order;
use App\Entity\OrderStatus;
use App\Message\PaymentProcessed;
use App\MessageHandler\OrderNotVisibleYet;
use App\MessageHandler\PaymentProcessedHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PaymentProcessedHandlerTest extends KernelTestCase
{
    #[TestWith([true, OrderStatus::Paid])]
    #[TestWith([false, OrderStatus::Declined])]
    public function testAppliesThePaymentOutcome(bool $approved, OrderStatus $expected): void
    {
        $order = $this->persistedOrder();

        $this->handler()(new PaymentProcessed($order->id(), $approved));

        self::assertSame($expected, $this->reload($order)->status());
    }

    public function testARedeliveredResultDoesNotFlipASettledOrder(): void
    {
        $order = $this->persistedOrder();

        $this->handler()(new PaymentProcessed($order->id(), true));
        $this->handler()(new PaymentProcessed($order->id(), false));

        self::assertSame(OrderStatus::Paid, $this->reload($order)->status());
    }

    public function testAResultForAnOrderNotVisibleYetIsRetried(): void
    {
        // ProcessPayment is published before the order commits, so the answer can outrun the commit.
        $this->expectException(OrderNotVisibleYet::class);

        $this->handler()(new PaymentProcessed(Uuid::v7()->toRfc4122(), true));
    }

    private function persistedOrder(): Order
    {
        $order = new Order(5000);
        $this->entityManager()->persist($order);
        $this->entityManager()->flush();

        return $order;
    }

    private function reload(Order $order): Order
    {
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(Order::class, $order->id());
        self::assertInstanceOf(Order::class, $reloaded);

        return $reloaded;
    }

    private function handler(): PaymentProcessedHandler
    {
        $handler = self::getContainer()->get(PaymentProcessedHandler::class);
        self::assertInstanceOf(PaymentProcessedHandler::class, $handler);

        return $handler;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
