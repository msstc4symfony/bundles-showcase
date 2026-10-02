<?php

declare(strict_types=1);

namespace App\Idempotency;

use RuntimeException;
use Throwable;

/**
 * @internal carries a failure of the guarded call through the cache so it is not mistaken for a storage failure
 */
final class CreationFailed extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct($previous->getMessage(), previous: $previous);
    }
}
