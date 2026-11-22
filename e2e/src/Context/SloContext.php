<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\GrafanaClient;
use App\E2e\Client\MetricsStorageClient;
use App\E2e\Client\PrometheusClient;
use App\E2e\Client\RecordingRule;
use App\E2e\ScrapeBarrier;
use App\E2e\Wait;
use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use LogicException;
use Webmozart\Assert\Assert;

/**
 * Window-group rules (5m interval) are checked by evaluating the loaded rule's query now, not by waiting for
 * their next evaluation.
 */
final class SloContext implements Context
{
    private const int RETRY_SECONDS = 20;

    // Gateway's METRICS_STORAGE_DSN in compose.yaml.
    private const int GATEWAY_METRICS_DATABASE = 1;

    private const string EVENTS_METRIC = 'symfony_slo_events_total';

    private readonly PrometheusClient $prometheus;

    // Created on first use: other scenarios need neither GRAFANA_URL nor the docker socket.
    private ?GrafanaClient $grafana = null;

    private ?MetricsStorageClient $storage = null;

    private readonly ScrapeBarrier $scrapeBarrier;

    /** @var array<non-empty-string, float> rule values by record, SLO and objective */
    private array $remembered = [];

    private ?RecordingRule $rememberedRule = null;

    private ?float $instant = null;

    /** @var string what the last poll of a waiting step saw, for its timeout message */
    private string $lastPoll = '';

    /** @var list<array{database: non-negative-int, key: non-empty-string, field: non-empty-string}> Redis fields written by this scenario */
    private array $injected = [];

    public function __construct()
    {
        $this->prometheus = new PrometheusClient();
        $this->scrapeBarrier = new ScrapeBarrier($this->prometheus);
    }

    // Injected events must not stay in the stand's storage, also when a step failed.
    #[AfterScenario]
    public function deleteInjectedEvents(): void
    {
        foreach ($this->injected as $field) {
            $this->storage()->delete($field['database'], $field['key'], $field['field']);
        }

        $this->injected = [];
    }

    #[Then('/^within (\d+) seconds Prometheus has evaluated the rule groups of ((?:"[^"]+"(?:, | and )?)+) without errors$/')]
    public function prometheusHasEvaluatedTheRuleGroups(int $seconds, string $quotedSlos): void
    {
        preg_match_all('/"([^"]+)"/', $quotedSlos, $matches);
        Assert::notEmpty($matches[1]);
        $expected = [];
        foreach ($matches[1] as $slo) {
            $expected[] = 'slo-' . $slo;
            $expected[] = 'slo-' . $slo . '-window';
        }

        Wait::until(
            function () use ($expected): bool {
                $problems = $this->ruleGroupProblems($expected);
                $described = [];
                foreach ($problems as $name => $problem) {
                    $described[] = $name . ': ' . $problem;
                }

                $this->lastPoll = implode('; ', $described);

                return $problems === [];
            },
            $seconds,
            fn (): string => sprintf('SLO rule groups not evaluated cleanly: %s.', $this->lastPoll),
        );
    }

    #[Then('within :seconds seconds Grafana has :count dashboards tagged :tag with distinct uids in the folder :folder')]
    public function grafanaHasDashboards(int $seconds, int $count, string $tag, string $folder): void
    {
        Assert::stringNotEmpty($tag);
        Wait::until(
            function () use ($tag, $count): bool {
                $found = \count($this->grafana()->dashboards($tag));
                $this->lastPoll = (string) $found;

                return $found >= $count;
            },
            $seconds,
            fn (): string => sprintf('Grafana has %s dashboards tagged "%s", expected %d.', $this->lastPoll, $tag, $count),
        );

        $dashboards = $this->grafana()->dashboards($tag);
        Assert::count($dashboards, $count);
        Assert::count(array_unique(array_column($dashboards, 'uid')), $count, 'Dashboard uids are not distinct.');
        foreach ($dashboards as $dashboard) {
            Assert::same($dashboard['folderTitle'], $folder, sprintf('Dashboard "%s" is not in the folder "%s".', $dashboard['title'], $folder));
        }
    }

    /**
     * @param non-empty-string $record
     */
    #[Then('the :record rule of :slo :objective reads :metric')]
    public function theRuleReads(string $record, string $slo, string $objective, string $metric): void
    {
        $rule = $this->prometheus->recordingRule($record, $slo, $objective);
        Assert::contains($rule->query, $metric, sprintf('The %s rule of %s %s does not read %s: %s', $record, $slo, $objective, $metric, $rule->query));
    }

    /**
     * @param non-empty-string $record
     */
    #[Then('the :record rule of :slo :objective evaluates to at least :min and at most :max')]
    public function theRuleEvaluatesToAtLeastAndAtMost(string $record, string $slo, string $objective, float $min, float $max): void
    {
        $value = $this->valueOf($this->prometheus->recordingRule($record, $slo, $objective));
        Assert::range($value, $min, $max, sprintf('The %s rule of %s %s is %s, expected [%s, %s].', $record, $slo, $objective, $value, $min, $max));
    }

    /**
     * The coverage rule's subquery runs on a whole-hour UTC grid ([<window>:1h]), so it is empty until the first
     * whole UTC hour after the first event: that is the warming-up state. Once the rule was evaluated past that hour
     * it must have a value. NaN fails in sample().
     *
     * @param non-empty-string $record
     */
    #[Then('the :record rule of :slo :objective has no value yet or evaluates to at least :min and below :max')]
    public function theRuleHasNoValueYetOrEvaluatesToAtLeastAndBelow(string $record, string $slo, string $objective, float $min, float $max): void
    {
        $rule = $this->prometheus->recordingRule($record, $slo, $objective);
        $value = $this->prometheus->sample($rule->query);
        if ($value === null) {
            $firstHour = $this->firstWholeHourAfterTheFirstEvent($rule);
            Assert::false(
                $firstHour !== null && $rule->lastEvaluationUnix !== null && $rule->lastEvaluationUnix > $firstHour,
                sprintf('The %s rule of %s %s has no value although it was evaluated after %s.', $record, $slo, $objective, $this->utc((float) $firstHour)),
            );
            echo sprintf('no value yet (warming up until %s)', $firstHour === null ? 'the first event' : $this->utc($firstHour));

            return;
        }

        Assert::true($value >= $min && $value < $max, sprintf('The %s rule of %s %s is %s, expected no value or [%s, %s).', $record, $slo, $objective, $value, $min, $max));
        echo sprintf('value %s', $value);
    }

    /**
     * t0 is 10 s before the first scrape that saw an event, so no event had been counted then; the target must
     * have been up at t0, or "no value" would only mean "not scraped".
     *
     * @param non-empty-string $record
     */
    #[Then('at an instant before the first event of :eventsSlo while :service was scraped, the :record rule of :slo :objective has no value')]
    public function atAnInstantBeforeTheFirstEventTheRuleHasNoValue(string $eventsSlo, string $service, string $record, string $slo, string $objective): void
    {
        // The subquery grid is 5 s: a series a few seconds old has no grid step yet.
        $firstSeenQuery = sprintf('min(min_over_time(timestamp(%s{slo="%s"})[6h:5s]))', self::EVENTS_METRIC, $eventsSlo);
        $firstSeen = null;
        Wait::until(
            function () use ($firstSeenQuery, &$firstSeen): bool {
                $firstSeen = $this->prometheus->sample($firstSeenQuery);

                return $firstSeen !== null;
            },
            self::RETRY_SECONDS,
            sprintf('%s has no %s events.', $eventsSlo, self::EVENTS_METRIC),
        );
        Assert::notNull($firstSeen, sprintf('%s has no %s events.', $eventsSlo, self::EVENTS_METRIC));
        $instant = $firstSeen - 10;
        Assert::same(
            $this->prometheus->sample(sprintf('max(up{service="%s"})', $service), $instant),
            1.0,
            'no idle instant before the first event; run on a fresh stack',
        );

        $rule = $this->prometheus->recordingRule($record, $slo, $objective);
        $value = $this->prometheus->sample($rule->query, $instant);
        Assert::null($value, sprintf('The %s rule of %s %s is %s at %s, expected no value.', $record, $slo, $objective, $value, $this->utc($instant)));
        $this->instant = $instant;
    }

    #[Then('at that instant :query has no sample')]
    public function atThatInstantHasNoSample(string $query): void
    {
        $instant = $this->instant ?? throw new LogicException('Pick an instant before the first event first.');
        $value = $this->prometheus->sample($query, $instant);
        Assert::null($value, sprintf('%s is %s at %s, expected no sample.', $query, $value, $this->utc($instant)));
    }

    /**
     * @param non-empty-string $record
     */
    #[Given('I remember the :record rule value of :slo :objective')]
    public function iRememberTheRuleValue(string $record, string $slo, string $objective): void
    {
        $this->scrapeBarrier->awaitIdleQueuesAndFreshScrape();
        $rule = $this->prometheus->recordingRule($record, $slo, $objective);
        $this->remembered[$this->key($record, $slo, $objective)] = $this->valueOf($rule);
        $this->rememberedRule = $rule;
    }

    /**
     * @param non-empty-string $record
     */
    #[Then('the :record rule value of :slo :objective is within :tolerance of the remembered one')]
    public function theRuleValueIsWithinOfTheRememberedOne(string $record, string $slo, string $objective, float $tolerance): void
    {
        $remembered = $this->remembered($record, $slo, $objective);
        $value = $this->valueOf($this->prometheus->recordingRule($record, $slo, $objective));
        Assert::lessThanEq(abs($value - $remembered), $tolerance, sprintf('The %s rule of %s %s moved from %s to %s.', $record, $slo, $objective, $remembered, $value));
    }

    /**
     * @param non-empty-string $record
     */
    #[Then('the :record rule value of :slo :objective is below the remembered one')]
    public function theRuleValueIsBelowTheRememberedOne(string $record, string $slo, string $objective): void
    {
        $remembered = $this->remembered($record, $slo, $objective);
        $rule = $this->prometheus->recordingRule($record, $slo, $objective);
        Wait::until(
            function () use ($rule, $remembered): bool {
                $value = $this->prometheus->sample($rule->query);
                $this->lastPoll = $value === null ? 'empty' : (string) $value;

                return $value !== null && $value < $remembered;
            },
            self::RETRY_SECONDS,
            fn (): string => sprintf('The %s rule of %s %s is %s, not below the remembered %s.', $record, $slo, $objective, $this->lastPoll, $remembered),
        );
    }

    #[Then('the same rule with revision :revision added to its filter is at least :delta below the remembered one')]
    public function theSameRuleWithTheRevisionAddedIsBelow(string $revision, float $delta): void
    {
        $rule = $this->rememberedRule ?? throw new LogicException('Remember a rule value first.');
        $remembered = $this->remembered($rule->record, $rule->labels['slo'] ?? '', $rule->labels['objective'] ?? '');
        $current = $rule->labels['revision'] ?? throw new LogicException(sprintf('The %s rule has no revision label.', $rule->record));
        $matcher = sprintf('revision="%s"', $current);
        Assert::contains($rule->query, $matcher, sprintf('The %s rule does not filter on %s: %s', $rule->record, $matcher, $rule->query));

        $widened = str_replace($matcher, sprintf('revision=~"%s|%s"', $current, $revision), $rule->query);
        $value = $this->prometheus->sample($widened);
        Assert::notNull($value, sprintf('%s returned no value.', $widened));
        Assert::lessThanEq($value, $remembered - $delta, sprintf('With revision %s the %s rule is %s, expected at most %s.', $revision, $rule->record, $value, $remembered - $delta));
    }

    /**
     * The new field is scraped at 0 before the increment: a counter series that appears at 1000 has no increase().
     */
    #[When('the gateway metrics storage gets :count bad :objective events of :slo under the revision :revision')]
    public function theGatewayMetricsStorageGetsBadEvents(int $count, string $objective, string $slo, string $revision): void
    {
        $current = $this->prometheus->recordingRule('slo:sli:ratio', $slo, $objective)->labels['revision']
            ?? throw new LogicException(sprintf('The slo:sli:ratio rule of %s %s has no revision label.', $slo, $objective));
        $database = self::GATEWAY_METRICS_DATABASE;
        $key = $this->storage()->counterKey($database, self::EVENTS_METRIC);

        $source = null;
        foreach ($this->storage()->fields($database, $key) as $field) {
            $labels = json_decode($field, true);
            if (\is_array($labels) && array_is_list($labels) && array_diff([$slo, $objective, $current], $labels) === []) {
                $source = $labels;

                break;
            }
        }

        Assert::notNull($source, sprintf('No %s field of %s %s revision %s in %s.', self::EVENTS_METRIC, $slo, $objective, $current, $key));
        $copy = json_encode(
            array_map(static fn (mixed $value): mixed => match ($value) {
                $current => $revision,
                'good' => 'bad',
                default => $value,
            }, $source),
            JSON_THROW_ON_ERROR,
        );

        $this->injected[] = ['database' => $database, 'key' => $key, 'field' => $copy];
        $this->storage()->increment($database, $key, $copy, 0);
        $series = sprintf('%s{slo="%s",objective="%s",revision="%s"} == 0', self::EVENTS_METRIC, $slo, $objective, $revision);
        Wait::until(
            fn (): bool => $this->prometheus->hasSeries($series),
            self::RETRY_SECONDS,
            sprintf('Prometheus did not scrape %s in time.', $series),
        );
        $this->storage()->increment($database, $key, $copy, $count);
    }

    #[Then(':query has no series')]
    public function hasNoSeries(string $query): void
    {
        Assert::false($this->prometheus->hasSeries($query), sprintf('%s has a series.', $query));
    }

    /**
     * Problems of the expected groups by name; empty when every one is loaded, evaluated and healthy. The recording
     * and the alerting group of an SLO share a name, so a problem of either sticks.
     *
     * @param list<non-empty-string> $expected
     *
     * @return array<string, string>
     */
    private function ruleGroupProblems(array $expected): array
    {
        $problems = array_fill_keys($expected, 'not loaded');
        $failed = [];
        foreach ($this->prometheus->ruleGroups() as $group) {
            $name = $group['name'];
            if (!\in_array($name, $expected, true)) {
                continue;
            }

            $problem = match (true) {
                $group['health'] !== 'ok' => 'health ' . $group['health'],
                $group['lastEvaluationUnix'] === null => 'not evaluated',
                default => null,
            };
            if ($problem !== null) {
                $problems[$name] = $problem;
                $failed[$name] = true;
            } elseif (!isset($failed[$name])) {
                unset($problems[$name]);
            }
        }

        return $problems;
    }

    /**
     * Read from the series the coverage rule wraps in timestamp(sum(...)); null before the first event.
     */
    private function firstWholeHourAfterTheFirstEvent(RecordingRule $rule): ?float
    {
        Assert::same(preg_match('/timestamp\(sum\((.+)\)\)\[/', $rule->query, $matches), 1, sprintf('The %s rule wraps no timestamp(sum(...)): %s', $rule->record, $rule->query));
        $firstEvent = $this->prometheus->sample(sprintf('min(min_over_time(timestamp(%s)[6h:5s]))', $matches[1]));

        return $firstEvent === null ? null : ceil($firstEvent / 3600) * 3600;
    }

    private function grafana(): GrafanaClient
    {
        return $this->grafana ??= new GrafanaClient();
    }

    private function storage(): MetricsStorageClient
    {
        return $this->storage ??= new MetricsStorageClient();
    }

    private function valueOf(RecordingRule $rule): float
    {
        $value = $this->prometheus->sample($rule->query);
        Assert::notNull($value, sprintf('The %s rule of %s %s has no value: %s', $rule->record, $rule->labels['slo'] ?? '?', $rule->labels['objective'] ?? '?', $rule->query));

        return $value;
    }

    private function remembered(string $record, string $slo, string $objective): float
    {
        return $this->remembered[$this->key($record, $slo, $objective)]
            ?? throw new LogicException(sprintf('Remember the %s rule value of %s %s first.', $record, $slo, $objective));
    }

    /**
     * @return non-empty-string
     */
    private function key(string $record, string $slo, string $objective): string
    {
        return $record . "\0" . $slo . "\0" . $objective;
    }

    private function utc(float $unix): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) $unix);
    }
}
