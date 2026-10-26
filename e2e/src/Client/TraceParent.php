<?php

declare(strict_types=1);

namespace App\E2e\Client;

use InvalidArgumentException;

/**
 * A W3C traceparent (or traceresponse) header value.
 */
final readonly class TraceParent
{
    private const string PATTERN = '/^00-((?!0{32})[0-9a-f]{32})-((?!0{16})[0-9a-f]{16})-([0-9a-f]{2})$/D';

    /**
     * @param non-empty-string $traceId
     * @param non-empty-string $spanId
     * @param non-empty-string $flags
     */
    private function __construct(
        public string $traceId,
        public string $spanId,
        public string $flags,
    ) {
    }

    public static function fromHeader(?string $value, string $name = 'traceparent'): self
    {
        if ($value === null || preg_match(self::PATTERN, $value, $parts) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s: %s" is not a valid traceparent-shaped value.', $name, $value ?? ''));
        }

        return new self($parts[1], $parts[2], $parts[3]);
    }
}
