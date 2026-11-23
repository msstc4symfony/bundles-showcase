<?php

declare(strict_types=1);

namespace App\Slo;

use RuntimeException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A range query sent in consecutive chunks of its window.
 */
final readonly class PendingRangeQuery
{
    private const array NON_FINITE = ['NaN', '+Inf', '-Inf'];

    /**
     * @param non-empty-string $expr
     * @param non-empty-list<ResponseInterface> $responses in window order
     */
    public function __construct(
        public string $expr,
        private array $responses,
    ) {
    }

    /**
     * @return list<RangeSeries> one per label set, the chunks joined
     */
    public function series(): array
    {
        $joined = [];
        foreach ($this->responses as $response) {
            foreach ($this->matrix($response) as $series) {
                $key = serialize($series->labels);
                $joined[$key] = isset($joined[$key])
                    ? new RangeSeries($series->labels, $joined[$key]->values + $series->values)
                    : $series;
            }
        }

        return array_values($joined);
    }

    /**
     * @return list<RangeSeries>
     */
    private function matrix(ResponseInterface $response): array
    {
        $body = $response->toArray(false);
        if (($body['status'] ?? null) !== 'success') {
            throw new RuntimeException(sprintf(
                'Prometheus did not evaluate "%s" (HTTP %d): %s',
                $this->expr,
                $response->getStatusCode(),
                \is_string($body['error'] ?? null) ? $body['error'] : 'no error message',
            ));
        }
        $data = \is_array($body['data'] ?? null) ? $body['data'] : [];
        if (($data['resultType'] ?? null) !== 'matrix' || !\is_array($data['result'] ?? null)) {
            throw new RuntimeException(sprintf('Prometheus answered "%s" with no matrix.', $this->expr));
        }

        $series = [];
        foreach ($data['result'] as $item) {
            $series[] = $this->rangeSeries($item);
        }

        return $series;
    }

    private function rangeSeries(mixed $item): RangeSeries
    {
        $metric = \is_array($item) && \is_array($item['metric'] ?? null) ? $item['metric'] : [];
        $labels = [];
        foreach ($metric as $name => $value) {
            if (\is_string($name) && $name !== '' && $name !== '__name__' && \is_string($value)) {
                $labels[$name] = $value;
            }
        }
        ksort($labels);

        $values = [];
        foreach (\is_array($item) && \is_array($item['values'] ?? null) ? $item['values'] : [] as $point) {
            $timestamp = \is_array($point) ? $point[0] ?? null : null;
            $value = \is_array($point) ? $point[1] ?? null : null;
            if (!\is_int($timestamp) || !\is_string($value) || (!is_numeric($value) && !\in_array($value, self::NON_FINITE, true))) {
                throw new RuntimeException(sprintf(
                    'Prometheus answered "%s" with the point %s, expected [whole Unix second, "value"].',
                    $this->expr,
                    Diagnostics::describe($point),
                ));
            }
            $values[$timestamp] = $value;
        }
        if ($values === []) {
            throw new RuntimeException(sprintf('Prometheus answered "%s" with a series without points.', $this->expr));
        }

        return new RangeSeries($labels, $values);
    }
}
