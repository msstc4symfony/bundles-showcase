<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Entity\Order;
use App\Search\ElasticaOrderIndex;
use App\Tests\Support\RecordingOperationMonitor;
use App\Tests\Support\ThrowingOperationMonitor;
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

        new ElasticaOrderIndex($index, new RecordingOperationMonitor(), new NullLogger())->add($order);
    }

    public function testASuccessfulIndexingSucceedsTheOperation(): void
    {
        $index = self::createStub(Index::class);
        $index->method('addDocument')->willReturn(new Response('{"result":"created"}', 201));
        $operations = new RecordingOperationMonitor();

        new ElasticaOrderIndex($index, $operations, new NullLogger())->add(new Order(5000));

        self::assertSame(['start orders.search_index', 'succeed', 'end'], $operations->calls());
        self::assertNull($operations->failure());
    }

    public function testAFailedIndexingFailsTheOperationLogsAndDoesNotThrow(): void
    {
        $failure = new NoNodeAvailableException('No alive nodes');
        $index = self::createStub(Index::class);
        $index->method('addDocument')->willThrowException($failure);
        $operations = new RecordingOperationMonitor();
        $logger = new BufferingLogger();

        new ElasticaOrderIndex($index, $operations, $logger)->add(new Order(5000));

        self::assertSame(['start orders.search_index', 'fail', 'end'], $operations->calls());
        self::assertSame($failure, $operations->failure());
        $logs = $logger->cleanLogs();
        self::assertCount(1, $logs);
        self::assertSame('error', $logs[0][0]);
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

        new ElasticaOrderIndex($index, new RecordingOperationMonitor(), $logger)->add($order);

        $logs = $logger->cleanLogs();
        self::assertCount(1, $logs);
        self::assertSame($order->id(), $logs[0][2]['orderId'] ?? null);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function brokenMonitors(): iterable
    {
        yield 'start throws, indexing works' => [true, false];
        yield 'start throws, indexing fails' => [true, true];
        yield 'handle throws, indexing works' => [false, false];
        yield 'handle throws, indexing fails' => [false, true];
    }

    #[DataProvider('brokenMonitors')]
    public function testABrokenMonitorNeverMakesAddThrow(bool $startThrows, bool $indexingFails): void
    {
        $index = self::createStub(Index::class);
        if ($indexingFails) {
            $index->method('addDocument')->willThrowException(new NoNodeAvailableException('No alive nodes'));
        } else {
            $index->method('addDocument')->willReturn(new Response('{"result":"created"}', 201));
        }

        $logger = new BufferingLogger();

        new ElasticaOrderIndex($index, new ThrowingOperationMonitor($startThrows), $logger)->add(new Order(5000));

        $levels = array_column($logger->cleanLogs(), 0);
        self::assertContains('warning', $levels);
        self::assertSame($indexingFails, \in_array('error', $levels, true));
    }
}
