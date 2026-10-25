<?php

declare(strict_types=1);

namespace App\E2e\Client;

use Webmozart\Assert\Assert;

/**
 * Spans of one trace as Tempo returns them (OTLP JSON with base64 ids).
 */
final readonly class TraceTree
{
    /**
     * @param list<TraceSpan> $spans
     */
    private function __construct(
        public array $spans,
    ) {
    }

    /**
     * @param array<array-key, mixed> $response GET /api/v2/traces/{id}
     */
    public static function fromTempo(array $response): self
    {
        $trace = $response['trace'] ?? [];
        Assert::isArray($trace);
        $spans = [];
        foreach (self::list($trace, 'resourceSpans') as $resourceSpans) {
            $service = self::serviceName($resourceSpans);
            foreach (self::list($resourceSpans, 'scopeSpans') as $scopeSpans) {
                foreach (self::list($scopeSpans, 'spans') as $span) {
                    Assert::string($span['name'] ?? null, 'A span in Tempo has no name.');
                    $parent = $span['parentSpanId'] ?? '';
                    $spans[] = new TraceSpan(
                        $service,
                        $span['name'],
                        self::hex($span['spanId'] ?? null),
                        $parent === '' ? '' : self::hex($parent),
                    );
                }
            }
        }

        return new self($spans);
    }

    /**
     * @return list<string>
     */
    public function services(): array
    {
        $services = array_map(static fn (TraceSpan $span): string => $span->service, $this->spans);
        $services = array_values(array_unique($services));
        sort($services);

        return $services;
    }

    /**
     * @param list<string> $services
     */
    public function hasSpansOf(array $services): bool
    {
        return array_diff($services, $this->services()) === [];
    }

    /**
     * @return list<string>
     */
    public function spanIds(): array
    {
        return array_map(static fn (TraceSpan $span): string => $span->spanId, $this->spans);
    }

    /**
     * Spans whose parent is neither in the trace nor one of the given span ids (the client's), as "service name".
     * A span without parent is an orphan too: the client sent a traceparent, so the trace has no root of its own.
     *
     * @param list<string> $outsideParents
     *
     * @return list<string>
     */
    public function orphans(array $outsideParents): array
    {
        $known = [...$this->spanIds(), ...$outsideParents];
        $orphans = [];
        foreach ($this->spans as $span) {
            if (!in_array($span->parentSpanId, $known, true)) {
                $orphans[] = $span->label();
            }
        }

        return $orphans;
    }

    /**
     * @param array<array-key, mixed> $parent
     *
     * @return list<array<array-key, mixed>>
     */
    private static function list(array $parent, string $key): array
    {
        $items = $parent[$key] ?? [];
        Assert::isList($items);
        Assert::allIsArray($items);

        return $items;
    }

    /**
     * @param array<array-key, mixed> $resourceSpans
     */
    private static function serviceName(array $resourceSpans): string
    {
        $resource = $resourceSpans['resource'] ?? [];
        Assert::isArray($resource);
        foreach (self::list($resource, 'attributes') as $attribute) {
            $value = $attribute['value'] ?? [];
            $isServiceName = ($attribute['key'] ?? null) === 'service.name';
            if ($isServiceName && is_array($value) && is_string($value['stringValue'] ?? null)) {
                return $value['stringValue'];
            }
        }

        return '';
    }

    /**
     * @return non-empty-string
     */
    private static function hex(mixed $base64): string
    {
        Assert::stringNotEmpty($base64, 'A span id in Tempo is missing or not a string.');
        $bytes = base64_decode($base64, true);
        Assert::string($bytes, sprintf('The span id "%s" from Tempo is not base64.', $base64));
        Assert::same(strlen($bytes), 8, sprintf('The span id "%s" from Tempo is not 8 bytes.', $base64));

        return bin2hex($bytes);
    }
}
