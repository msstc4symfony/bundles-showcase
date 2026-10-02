<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Message\PaymentProcessed;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class FailureTransportTest extends KernelTestCase
{
    private const int BEYOND_ANY_RETRY_LIMIT = 10;

    public function testAMessageThatExhaustedItsRetriesIsKept(): void
    {
        self::bootKernel();
        $event = new WorkerMessageFailedEvent(
            new Envelope(new PaymentProcessed('0190a5b2-0000-7000-8000-000000000000', true), [new RedeliveryStamp(self::BEYOND_ANY_RETRY_LIMIT), new ReceivedStamp('payment_processed')]),
            'payment_processed',
            new RuntimeException('handler failed'),
        );

        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch($event);

        $failed = self::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(InMemoryTransport::class, $failed);
        self::assertCount(1, $failed->getSent());
    }
}
