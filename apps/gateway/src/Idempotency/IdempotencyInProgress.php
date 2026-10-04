<?php

declare(strict_types=1);

namespace App\Idempotency;

use RuntimeException;

/**
 * An earlier request with this key started but its outcome was never recorded (it crashed, or the answer
 * could not be stored); calling the upstream again could create a duplicate. Concurrent duplicates never
 * end up here: the store's lock makes them wait for the first request's answer.
 */
final class IdempotencyInProgress extends RuntimeException
{
}
