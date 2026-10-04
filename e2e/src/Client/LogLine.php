<?php

declare(strict_types=1);

namespace App\E2e\Client;

use JsonException;

final readonly class LogLine
{
    /**
     * @param array<array-key, mixed> $extra
     */
    public function __construct(
        public string $service,
        public string $message,
        public array $extra,
    ) {
    }

    public static function fromJson(string $service, string $line): self
    {
        try {
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new self($service, $line, []);
        }

        if (!is_array($record)) {
            return new self($service, $line, []);
        }

        $message = $record['message'] ?? '';
        $extra = $record['extra'] ?? [];

        return new self($service, is_string($message) ? $message : '', is_array($extra) ? $extra : []);
    }

    public function requestFrom(): ?string
    {
        return $this->extraString('request_from');
    }

    public function requestId(): ?string
    {
        return $this->extraString('request_id');
    }

    public function runtimeId(): ?string
    {
        return $this->extraString('runtime_id');
    }

    private function extraString(string $key): ?string
    {
        $value = $this->extra[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
