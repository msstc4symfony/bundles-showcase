<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Order;

/**
 * Search projection of orders. Best effort: a failure is logged by the implementation, never thrown, because
 * the order is already committed when it is indexed. A lost document is not re-indexed later.
 */
interface OrderIndex
{
    public function add(Order $order): void;
}
