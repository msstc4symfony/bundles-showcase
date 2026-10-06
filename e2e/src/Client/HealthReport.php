<?php

declare(strict_types=1);

namespace App\E2e\Client;

use JsonException;

final readonly class HealthReport
{
    /**
     * @param list<string> $errors
     * @param list<string> $messages
     * @param list<string> $warnings failures of non-critical checks; they do not fail readiness
     */
    private function __construct(
        public int $status,
        public bool $success,
        public array $errors,
        public array $messages,
        public array $warnings,
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
            return new self($status, false, [], [], [], $body);
        }

        return new self(
            $status,
            ($decoded['success'] ?? null) === true,
            self::strings($decoded['errors'] ?? []),
            self::strings($decoded['messages'] ?? []),
            self::strings($decoded['warnings'] ?? []),
            $body,
        );
    }

    public static function unreachable(string $reason): self
    {
        return new self(0, false, [], [], [], $reason);
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
     * @param non-empty-string $pattern PCRE pattern, delimiters included
     */
    public function hasLineMatching(string $pattern): bool
    {
        return $this->anyMatches([...$this->messages, ...$this->errors], $pattern);
    }

    /**
     * @param non-empty-string $pattern PCRE pattern, delimiters included
     */
    public function warnsMatching(string $pattern): bool
    {
        return $this->anyMatches($this->warnings, $pattern);
    }

    /**
     * @param list<string> $lines
     * @param non-empty-string $pattern
     */
    private function anyMatches(array $lines, string $pattern): bool
    {
        return array_any($lines, static fn (string $line): bool => preg_match($pattern, $line) === 1);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
