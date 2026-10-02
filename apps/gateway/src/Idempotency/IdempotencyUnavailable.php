<?php

declare(strict_types=1);

namespace App\Idempotency;

use RuntimeException;

final class IdempotencyUnavailable extends RuntimeException
{
}
