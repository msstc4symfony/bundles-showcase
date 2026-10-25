<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Webmozart\Assert\Assert;

/**
 * One span of a trace read back from Tempo.
 */
final readonly class TraceSpan
{
    /**
     * @param string $spanId 16 hex digits
     * @param string $parentSpanId 16 hex digits; '' for a span without parent
     */
    public function __construct(
        public string $service,
        public string $name,
        public string $spanId,
        public string $parentSpanId,
    ) {
        Assert::regex($spanId, LogLine::SPAN_ID_PATTERN, sprintf('"%s" is not a span id.', $spanId));
        if ($parentSpanId !== '') {
            $message = sprintf('"%s" is not a parent span id.', $parentSpanId);
            Assert::regex($parentSpanId, LogLine::SPAN_ID_PATTERN, $message);
        }
    }

    public function label(): string
    {
        return $this->service . ' ' . $this->name;
    }
}
