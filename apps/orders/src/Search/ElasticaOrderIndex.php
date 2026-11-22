<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Order;
use DateTimeInterface;
use Elastica\Document;
use Elastica\Index;
use Msstc4Symfony\SloBundle\Application\Port\OperationMonitorInterface;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

final readonly class ElasticaOrderIndex implements OrderIndex
{
    /** Declared under msstc4symfony_slo.operations. */
    public const string OPERATION = 'orders.search_index';

    public function __construct(
        #[Autowire(service: 'fos_elastica.index.orders')]
        private Index $index,
        private OperationMonitorInterface $operations,
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function add(Order $order): void
    {
        $operation = $this->operations->start(self::OPERATION);
        try {
            $this->index->addDocument(new Document($order->id(), [
                'id' => $order->id(),
                'status' => $order->status()->value,
                'amount' => $order->amount(),
                'createdAt' => $order->createdAt()->format(DateTimeInterface::ATOM),
            ]));
            $operation->succeed();
        } catch (Throwable $exception) {
            $operation->fail($exception);
            // The order is already committed: nothing may turn its 201 into an error.
            $this->logger->error('Order {orderId} was not indexed: {reason}', [
                'orderId' => $order->id(),
                'reason' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        } finally {
            $operation->end();
        }
    }
}
