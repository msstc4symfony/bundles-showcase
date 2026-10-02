<?php

declare(strict_types=1);

namespace App\Idempotency;

use RuntimeException;

final class IdempotencyKeyReused extends RuntimeException
{
}
