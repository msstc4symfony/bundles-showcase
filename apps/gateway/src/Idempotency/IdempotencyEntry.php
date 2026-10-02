<?php

declare(strict_types=1);

namespace App\Idempotency;

use JsonException;

/**
 * @internal stored form of one idempotency key: the request fingerprint and, once created, the answer
 */
final readonly class IdempotencyEntry
{
    public function __construct(
        public string $fingerprint,
        public ?string $value,
    ) {
    }

    /**
     * Anything unreadable (an older format, a truncated write) counts as no entry.
     */
    public static function fromStored(mixed $stored): ?self
    {
        if (!is_string($stored)) {
            return null;
        }

        try {
            $decoded = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded) || !is_string($decoded['fingerprint'] ?? null)) {
            return null;
        }

        $value = $decoded['value'] ?? null;

        return new self($decoded['fingerprint'], is_string($value) ? $value : null);
    }

    public function toStored(): string
    {
        return json_encode(['fingerprint' => $this->fingerprint, 'value' => $this->value], JSON_THROW_ON_ERROR);
    }
}
