<?php

declare(strict_types=1);

namespace App\Idempotency;

use JsonException;
use LogicException;

/**
 * @internal stored form of one idempotency key: the request fingerprint and, once created, the answer
 */
final readonly class IdempotencyEntry
{
    private function __construct(
        private string $fingerprint,
        private ?string $value,
    ) {
    }

    public static function pending(string $fingerprint): self
    {
        return new self($fingerprint, null);
    }

    public static function completed(string $fingerprint, string $value): self
    {
        return new self($fingerprint, $value);
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

    public function isPending(): bool
    {
        return $this->value === null;
    }

    public function matches(string $fingerprint): bool
    {
        return $this->fingerprint === $fingerprint;
    }

    public function value(): string
    {
        return $this->value ?? throw new LogicException('A pending idempotency entry has no value.');
    }

    public function toStored(): string
    {
        return json_encode(['fingerprint' => $this->fingerprint, 'value' => $this->value], JSON_THROW_ON_ERROR);
    }
}
