<?php

declare(strict_types=1);

namespace App\Application\Order\Create;

use App\Entity\Order;
use App\Message\ProcessPayment;
use App\Order\OrderView;
use App\Search\OrderIndex;
use Doctrine\ORM\EntityManagerInterface;
use Msstc4Symfony\LogicBundle\Application\Action\ActionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Webmozart\Assert\Assert;

/**
 * @implements ActionInterface<Output>
 */
final readonly class Action implements ActionInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private OrderIndex $search,
    ) {
    }

    public function __invoke(Input $input): Output
    {
        // Already validated by the input constraints; the assertion narrows mixed to int.
        Assert::integer($input->amount);
        $order = new Order($input->amount);

        // Flush before dispatch so a database error never publishes a payment for a missing order;
        // a failed AMQP send still rolls the order back.
        $this->entityManager->wrapInTransaction(function () use ($order): void {
            $this->entityManager->persist($order);
            $this->entityManager->flush();

            $this->bus->dispatch(new ProcessPayment($order->id(), $order->amount()));
        });

        // After the commit: the search index is a projection and must never hold an order the database rolled back.
        $this->search->add($order);

        return new Output(OrderView::of($order));
    }
}
