<?php

declare(strict_types=1);

namespace App\E2e\Client;

use JsonException;

final readonly class HealthReport
{
    /**
     * @param list<string> $errors
     * @param list<string> $messages
     */
    private function __construct(
        public int $status,
        public bool $success,
        public array $errors,
        public array $messages,
        public string $raw,
    ) {
    }

    public static function fromResponse(int $status, string $body): self
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        if (!is_array($decoded)) {
            return new self($status, false, [], [], $body);
        }

        return new self(
            $status,
            ($decoded['success'] ?? null) === true,
            self::strings($decoded['errors'] ?? []),
            self::strings($decoded['messages'] ?? []),
            $body,
        );
    }

    public static function unreachable(string $reason): self
    {
        return new self(0, false, [], [], $reason);
    }

    public function isUp(): bool
    {
        return $this->status === 200 && $this->success;
    }

    /**
     * Down means the service answered and reported a failed dependency, not that it is unreachable.
     */
    public function isDown(): bool
    {
        return $this->status !== 0 && !$this->isUp();
    }

    public function mentions(string $fragment): bool
    {
        return array_any([...$this->messages, ...$this->errors], fn (string $line): bool => str_contains($line, $fragment));
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
