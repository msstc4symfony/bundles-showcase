<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Message\ProcessPayment;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class CreateOrderControllerTest extends WebTestCase
{
    public function testCreatesPendingOrderAndRequestsPayment(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/orders', ['amount' => 5000]);

        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('pending', $body['status'] ?? null);
        self::assertSame(5000, $body['amount'] ?? null);
        self::assertIsString($body['id'] ?? null);

        $transport = self::getContainer()->get('messenger.transport.process_payment');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertEquals(new ProcessPayment($body['id'], 5000), $sent[0]->getMessage());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[TestWith([['amount' => 0]])]
    #[TestWith([['amount' => -5]])]
    #[TestWith([['amount' => '12']])]
    #[TestWith([['amount' => 12.5]])]
    #[TestWith([[]])]
    public function testRejectsInvalidAmount(array $payload): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/orders', $payload);

        self::assertResponseStatusCodeSame(422);
        $transport = self::getContainer()->get('messenger.transport.process_payment');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertSame([], $transport->getSent());
    }
}
