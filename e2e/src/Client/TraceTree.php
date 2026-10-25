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
     * @param list<array{service: string, name: string, spanId: string, parentSpanId: string}> $spans ids in hex; '' for a root
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
                    $spans[] = [
                        'service' => $service,
                        'name' => is_string($span['name'] ?? null) ? $span['name'] : '',
                        'spanId' => self::hex($span['spanId'] ?? null),
                        'parentSpanId' => self::hex($span['parentSpanId'] ?? null),
                    ];
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
        $services = array_values(array_unique(array_column($this->spans, 'service')));
        sort($services);

        return $services;
    }

    /**
     * Spans whose parent is neither in the trace nor one of the given span ids (the client's), as "service name".
     *
     * @param list<string> $outsideParents
     *
     * @return list<string>
     */
    public function orphans(array $outsideParents): array
    {
        $known = [...array_column($this->spans, 'spanId'), ...$outsideParents, ''];
        $orphans = [];
        foreach ($this->spans as $span) {
            if (!in_array($span['parentSpanId'], $known, true)) {
                $orphans[] = $span['service'] . ' ' . $span['name'];
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
            if (($attribute['key'] ?? null) === 'service.name' && is_array($value) && is_string($value['stringValue'] ?? null)) {
                return $value['stringValue'];
            }
        }

        return '';
    }

    private static function hex(mixed $base64): string
    {
        if (!is_string($base64) || $base64 === '') {
            return '';
        }

        $bytes = base64_decode($base64, true);

        return $bytes === false ? '' : bin2hex($bytes);
    }
}
