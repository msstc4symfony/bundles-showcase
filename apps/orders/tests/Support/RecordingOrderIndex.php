<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Order;
use App\Search\OrderIndex;
use Override;

/**
 * Replaces the Elasticsearch index in the test kernel: the app tests run without Elasticsearch.
 */
final class RecordingOrderIndex implements OrderIndex
{
    /** @var list<string> ids of the added orders */
    public array $added = [];

    #[Override]
    public function add(Order $order): void
    {
        $this->added[] = $order->id();
    }
}
