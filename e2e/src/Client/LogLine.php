<?php

declare(strict_types=1);

namespace App\E2e\Client;

use JsonException;

final readonly class LogLine
{
    public const string SPAN_ID_PATTERN = '/^(?!0{16})[0-9a-f]{16}$/D';

    /**
     * @param array<array-key, mixed> $record the decoded JSON record; empty for a line that is not JSON
     */
    private function __construct(
        public string $service,
        public string $message,
        private array $record,
    ) {
    }

    public static function fromJson(string $service, string $line): self
    {
        try {
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new self($service, $line, []);
        }

        if (!is_array($record) || array_is_list($record)) {
            return new self($service, $line, []);
        }

        $message = $record['message'] ?? '';

        return new self($service, is_string($message) ? $message : '', $record);
    }

    public function traceId(): ?string
    {
        return $this->topLevelString('trace_id');
    }

    public function spanId(): ?string
    {
        return $this->topLevelString('span_id');
    }

    public function parentSpanId(): ?string
    {
        return $this->topLevelString('parent_span_id');
    }

    public function channel(): ?string
    {
        return $this->topLevelString('channel');
    }

    public function caller(): ?string
    {
        return $this->extraString('caller');
    }

    public function runtimeId(): ?string
    {
        return $this->extraString('runtime_id');
    }

    public function path(): ?string
    {
        return $this->extraString('path');
    }

    public function baggage(): ?string
    {
        return $this->extraString('baggage');
    }

    public function contextString(string $key): ?string
    {
        return $this->nestedString('context', $key);
    }

    public function hasContextKey(string $key): bool
    {
        return array_key_exists($key, $this->section('context'));
    }

    public function contextBool(string $key): bool
    {
        return ($this->section('context')[$key] ?? null) === true;
    }

    public function hasTopLevelKey(string $key): bool
    {
        return array_key_exists($key, $this->record);
    }

    public function hasExtraKey(string $key): bool
    {
        return array_key_exists($key, $this->section('extra'));
    }

    private function topLevelString(string $key): ?string
    {
        $value = $this->record[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function extraString(string $key): ?string
    {
        return $this->nestedString('extra', $key);
    }

    private function nestedString(string $section, string $key): ?string
    {
        $value = $this->section($section)[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function section(string $name): array
    {
        $section = $this->record[$name] ?? [];

        return is_array($section) ? $section : [];
    }
}
