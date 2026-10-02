<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class FailureTransportTest extends KernelTestCase
{
    public function testMessagesThatExhaustRetriesAreKept(): void
    {
        self::bootKernel();

        self::assertInstanceOf(InMemoryTransport::class, self::getContainer()->get('messenger.transport.failed'));
    }
}
