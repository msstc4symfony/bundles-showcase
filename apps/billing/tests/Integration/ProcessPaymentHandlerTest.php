<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Message\PaymentProcessed;
use App\Message\ProcessPayment;
use App\MessageHandler\ProcessPaymentHandler;
use App\Repository\PaymentRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class ProcessPaymentHandlerTest extends KernelTestCase
{
    public function testApprovesAndReplies(): void
    {
        $orderId = Uuid::v7()->toRfc4122();

        $this->handler()(new ProcessPayment($orderId, 5000));

        self::assertEquals([new PaymentProcessed($orderId, true)], $this->replies());
    }

    public function testDeclinesAboveTheLimit(): void
    {
        $orderId = Uuid::v7()->toRfc4122();

        $this->handler()(new ProcessPayment($orderId, 150000));

        self::assertEquals([new PaymentProcessed($orderId, false)], $this->replies());
    }

    public function testRedeliveryDoesNotPayTwice(): void
    {
        $orderId = Uuid::v7()->toRfc4122();
        $message = new ProcessPayment($orderId, 5000);

        $this->handler()($message);
        $this->handler()($message);

        $payments = self::getContainer()->get(PaymentRepository::class);
        self::assertInstanceOf(PaymentRepository::class, $payments);
        self::assertSame(1, $payments->count(['orderId' => $orderId]));
        // A redelivery still answers, so orders is not left pending.
        self::assertEquals([new PaymentProcessed($orderId, true), new PaymentProcessed($orderId, true)], $this->replies());
    }

    private function handler(): ProcessPaymentHandler
    {
        $handler = self::getContainer()->get(ProcessPaymentHandler::class);
        self::assertInstanceOf(ProcessPaymentHandler::class, $handler);

        return $handler;
    }

    /**
     * @return list<object>
     */
    private function replies(): array
    {
        $transport = self::getContainer()->get('messenger.transport.payment_processed');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent()));
    }
}
