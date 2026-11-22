<?php

declare(strict_types=1);

namespace App\E2e\Client;

use DateTimeImmutable;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webmozart\Assert\Assert;

final readonly class PrometheusClient
{
    private HttpClientInterface $http;

    public function __construct(?string $baseUri = null)
    {
        $baseUri ??= getenv('PROMETHEUS_URL');
        Assert::stringNotEmpty($baseUri, 'PROMETHEUS_URL is not set.');
        $this->http = HttpClient::createForBaseUri($baseUri, ['timeout' => 10]);
    }

    /**
     * Unix time of the oldest last successful scrape across the showcase targets; 0.0 while any target is down.
     *
     * @param positive-int $targets
     */
    public function oldestSuccessfulScrape(int $targets): float
    {
        // A failed scrape still records up=0 with a fresh timestamp, but brings no samples.
        // Counted per service: right after "make up" a recreated service briefly has two instances.
        if ($this->scalar('count(max by (service) (up{job="showcase"} == 1))', null) < $targets) {
            return 0.0;
        }

        // timestamp() must wrap the raw selector: after a comparison it reports the evaluation time instead.
        return $this->scalar('min(max by (service) (timestamp(up{job="showcase"}) and up{job="showcase"} == 1))', null);
    }

    /**
     * Sum of the instant vector (0.0 when it is empty), optionally evaluated at a past moment.
     */
    public function value(string $promQl, ?float $atUnix = null): float
    {
        return $this->scalar(sprintf('sum(%s)', $promQl), $atUnix);
    }

    public function hasSeries(string $promQl): bool
    {
        return $this->vector($promQl, null) !== [];
    }

    /**
     * The single sample of the instant vector; null when the vector is empty, so NoData is never read as 0.
     */
    public function sample(string $promQl, ?float $atUnix = null): ?float
    {
        $result = $this->vector($promQl, $atUnix);
        if ($result === []) {
            return null;
        }

        Assert::count($result, 1, sprintf('%s returned %d series, expected at most one.', $promQl, \count($result)));
        $value = $result[0]['value'] ?? null;
        Assert::isList($value);
        Assert::numeric($value[1] ?? null, sprintf('%s returned the non-numeric sample "%s".', $promQl, json_encode($value[1] ?? null)));

        return (float) $value[1];
    }

    /**
     * @param non-empty-string $record
     */
    public function recordingRule(string $record, string $slo, string $objective): RecordingRule
    {
        $matches = [];
        foreach ($this->groups('/api/v1/rules?type=record&rule_name[]=' . rawurlencode($record)) as $group) {
            foreach ($this->rules($group) as $rule) {
                if (($rule['name'] ?? null) !== $record) {
                    continue;
                }

                $labels = $this->labels($rule['labels'] ?? []);
                if (($labels['slo'] ?? null) === $slo && ($labels['objective'] ?? null) === $objective) {
                    $query = $rule['query'] ?? null;
                    Assert::stringNotEmpty($query);
                    $health = $rule['health'] ?? null;
                    Assert::string($health);
                    $matches[] = new RecordingRule($record, $query, $labels, $health, $this->unixTime($rule['lastEvaluation'] ?? null));
                }
            }
        }

        Assert::count($matches, 1, sprintf('Expected one "%s" rule of %s %s, Prometheus has %d.', $record, $slo, $objective, \count($matches)));

        return $matches[0];
    }

    /**
     * Every loaded group (recording and alerting groups may share a name). Health and evaluation time come from
     * the group's rules: "err" or "unknown" when any rule has it, the oldest rule evaluation time.
     *
     * @return list<array{name: string, health: string, lastEvaluationUnix: ?float}>
     */
    public function ruleGroups(): array
    {
        $groups = [];
        foreach ($this->groups('/api/v1/rules') as $group) {
            $name = $group['name'] ?? null;
            Assert::string($name);
            $health = 'ok';
            $lastEvaluation = null;
            $evaluated = true;
            foreach ($this->rules($group) as $rule) {
                $ruleHealth = $rule['health'] ?? null;
                Assert::string($ruleHealth);
                if ($ruleHealth !== 'ok' && $health !== 'err') {
                    $health = $ruleHealth;
                }

                $ruleEvaluation = $this->unixTime($rule['lastEvaluation'] ?? null);
                if ($ruleEvaluation === null) {
                    $evaluated = false;
                } else {
                    $lastEvaluation = $lastEvaluation === null ? $ruleEvaluation : min($lastEvaluation, $ruleEvaluation);
                }
            }

            $groups[] = ['name' => $name, 'health' => $health, 'lastEvaluationUnix' => $evaluated ? $lastEvaluation : null];
        }

        return $groups;
    }

    private function scalar(string $promQl, ?float $atUnix): float
    {
        return $this->sample($promQl, $atUnix) ?? 0.0;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function vector(string $promQl, ?float $atUnix): array
    {
        $query = ['query' => $promQl];
        if ($atUnix !== null) {
            $query['time'] = sprintf('%.3F', $atUnix);
        }

        $payload = $this->data('/api/v1/query', $query);
        Assert::same($payload['resultType'] ?? null, 'vector', sprintf('%s is not an instant vector.', $promQl));
        $result = $payload['result'] ?? null;
        Assert::isList($result);
        Assert::allIsArray($result);

        return $result;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function groups(string $path): array
    {
        $groups = $this->data($path, [])['groups'] ?? null;
        Assert::isList($groups);
        Assert::allIsArray($groups);

        return $groups;
    }

    /**
     * @param array<array-key, mixed> $group
     *
     * @return list<array<array-key, mixed>>
     */
    private function rules(array $group): array
    {
        $rules = $group['rules'] ?? null;
        Assert::isList($rules);
        Assert::allIsArray($rules);

        return $rules;
    }

    /**
     * @return array<non-empty-string, string>
     */
    private function labels(mixed $labels): array
    {
        Assert::isArray($labels);
        $typed = [];
        foreach ($labels as $name => $value) {
            Assert::stringNotEmpty($name);
            Assert::string($value);
            $typed[$name] = $value;
        }

        return $typed;
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<array-key, mixed>
     */
    private function data(string $path, array $query): array
    {
        $response = $this->http->request('GET', $path, ['query' => $query])->toArray();
        Assert::same($response['status'] ?? null, 'success', sprintf('Prometheus %s failed: %s', $path, json_encode($response['error'] ?? null)));
        $data = $response['data'] ?? null;
        Assert::isArray($data);

        return $data;
    }

    /**
     * Prometheus reports "never" as Go's zero time, 0001-01-01T00:00:00Z.
     */
    private function unixTime(mixed $rfc3339): ?float
    {
        Assert::string($rfc3339);
        if (str_starts_with($rfc3339, '0001-01-01')) {
            return null;
        }

        // PHP parses at most microseconds; Prometheus writes nanoseconds.
        $microseconds = preg_replace('/(\.\d{6})\d+/', '$1', $rfc3339);
        Assert::string($microseconds);

        return (float) new DateTimeImmutable($microseconds)->format('U.u');
    }
}
