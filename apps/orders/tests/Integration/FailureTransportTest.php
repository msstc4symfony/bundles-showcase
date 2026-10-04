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
    private const string TRANSPORT = 'payment_processed';

    // Above the configured max_retries of the transport, so the retry strategy gives up.
    private const int EXHAUSTED_RETRIES = 100;

    public function testAMessageThatExhaustedItsRetriesIsKept(): void
    {
        $this->failHandling(self::EXHAUSTED_RETRIES);

        self::assertCount(1, $this->transport('failed')->getSent());
        self::assertSame([], $this->transport(self::TRANSPORT)->getSent());
    }

    public function testAMessageWithRetriesLeftIsRetriedNotKept(): void
    {
        $this->failHandling(0);

        self::assertCount(1, $this->transport(self::TRANSPORT)->getSent());
        self::assertSame([], $this->transport('failed')->getSent());
    }

    private function failHandling(int $retries): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $dispatcher->dispatch(new WorkerMessageFailedEvent(
            new Envelope(new PaymentProcessed('0190a5b2-0000-7000-8000-000000000000', true), [new RedeliveryStamp($retries), new ReceivedStamp(self::TRANSPORT)]),
            self::TRANSPORT,
            new RuntimeException('handler failed'),
        ));
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.' . $name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
