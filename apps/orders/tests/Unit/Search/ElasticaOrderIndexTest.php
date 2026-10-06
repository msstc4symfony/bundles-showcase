<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Entity\Order;
use App\Search\ElasticaOrderIndex;
use DateTimeInterface;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Elastica\Document;
use Elastica\Index;
use Elastica\Response;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Throwable;

final class ElasticaOrderIndexTest extends TestCase
{
    public function testAddsTheOrderUnderItsId(): void
    {
        $order = new Order(5000);
        $index = $this->createMock(Index::class);
        $index->expects(self::once())
            ->method('addDocument')
            ->with(self::callback(static function (Document $document) use ($order): bool {
                self::assertSame($order->id(), $document->getId());
                self::assertSame([
                    'id' => $order->id(),
                    'status' => 'pending',
                    'amount' => 5000,
                    'createdAt' => $order->createdAt()->format(DateTimeInterface::ATOM),
                ], $document->getData());

                return true;
            }))
            ->willReturn(new Response('{"result":"created"}', 201))
        ;

        new ElasticaOrderIndex($index, new NullLogger())->add($order);
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function failures(): iterable
    {
        yield 'unreachable cluster' => [new NoNodeAvailableException('No alive nodes')];
        yield 'PSR-18 client error' => [new class('Invalid request') extends RuntimeException implements ClientExceptionInterface {}];
        yield 'anything else' => [new LogicException('Unexpected')];
    }

    #[DataProvider('failures')]
    public function testAFailureIsLoggedInsteadOfThrown(Throwable $failure): void
    {
        $order = new Order(5000);
        $index = self::createStub(Index::class);
        $index->method('addDocument')->willThrowException($failure);
        $logger = new BufferingLogger();

        new ElasticaOrderIndex($index, $logger)->add($order);

        $logs = $logger->cleanLogs();
        self::assertCount(1, $logs);
        self::assertSame('error', $logs[0][0]);
        self::assertSame($order->id(), $logs[0][2]['orderId'] ?? null);
    }
}
