<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ShowOrderControllerTest extends WebTestCase
{
    public function testShowsACreatedOrder(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/orders', ['amount' => 700]);

        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($created);
        self::assertIsString($created['id'] ?? null);

        $client->request('GET', '/orders/' . $created['id']);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['id' => $created['id'], 'status' => 'pending', 'amount' => 700],
            json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    #[TestWith(['0190e0f6-0000-7000-8000-00000000ffff'])]
    #[TestWith(['not-a-uuid'])]
    public function testUnknownOrMalformedIdIsNotFound(string $id): void
    {
        $client = self::createClient();
        $client->request('GET', '/orders/' . $id);

        self::assertResponseStatusCodeSame(404);
    }
}
