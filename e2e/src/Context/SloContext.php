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
use Behat\Hook\BeforeSuite;
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

    private readonly GrafanaClient $grafana;

    private readonly MetricsStorageClient $storage;

    private readonly ScrapeBarrier $scrapeBarrier;

    /** @var array<string, float> rule values by record, SLO and objective */
    private array $remembered = [];

    private ?RecordingRule $lastRule = null;

    private ?float $instant = null;

    /** @var string what the last poll of a waiting step saw, for its timeout message */
    private string $lastPoll = '';

    /** @var list<array{database: int, key: non-empty-string, field: string}> Redis fields written by this scenario */
    private array $injected = [];

    public function __construct()
    {
        $this->prometheus = new PrometheusClient();
        $this->grafana = new GrafanaClient();
        $this->storage = new MetricsStorageClient();
        $this->scrapeBarrier = new ScrapeBarrier($this->prometheus);
    }

    /**
     * The NoData scenario needs an instant before the stack's first SLO event at which orders was scraped; on a stack
     * just started, the first scenarios send traffic before Prometheus has seen the targets.
     */
    #[BeforeSuite]
    public static function waitForSteadyScrapes(): void
    {
        new ScrapeBarrier()->awaitTargetsScrapedFor(20, 90);
    }

    // Injected events must not stay in the stand's storage, also when a step failed.
    #[AfterScenario]
    public function deleteInjectedEvents(): void
    {
        foreach ($this->injected as $field) {
            $this->storage->delete($field['database'], $field['key'], $field['field']);
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
                $problems = array_fill_keys($expected, 'not loaded');
                // The recording and the alerting group of an SLO share a name: a problem of either sticks.
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
        Wait::until(
            function () use ($tag, $count): bool {
                $found = \count($this->grafana->dashboards($tag));
                $this->lastPoll = (string) $found;

                return $found >= $count;
            },
            $seconds,
            fn (): string => sprintf('Grafana has %s dashboards tagged "%s", expected %d.', $this->lastPoll, $tag, $count),
        );

        $dashboards = $this->grafana->dashboards($tag);
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
        $rule = $this->rule($record, $slo, $objective);
        Assert::contains($rule->query, $metric, sprintf('The %s rule of %s %s does not read %s: %s', $record, $slo, $objective, $metric, $rule->query));
    }

    /**
     * @param non-empty-string $record
     */
    #[Then('the :record rule of :slo :objective evaluates to at least :min and at most :max')]
    public function theRuleEvaluatesToAtLeastAndAtMost(string $record, string $slo, string $objective, float $min, float $max): void
    {
        $value = $this->valueOf($this->rule($record, $slo, $objective));
        Assert::range($value, $min, $max, sprintf('The %s rule of %s %s is %s, expected [%s, %s].', $record, $slo, $objective, $value, $min, $max));
    }

    /**
     * The coverage rule's subquery runs on a whole-hour UTC grid ([<window>:1h]), so it is empty until the first
     * whole hour after the first event: that is the warming-up state, not a failure. NaN fails in sample().
     *
     * @param non-empty-string $record
     */
    #[Then('the :record rule of :slo :objective has no value yet or evaluates to at least :min and below :max')]
    public function theRuleHasNoValueYetOrEvaluatesToAtLeastAndBelow(string $record, string $slo, string $objective, float $min, float $max): void
    {
        $rule = $this->rule($record, $slo, $objective);
        $value = $this->prometheus->sample($rule->query);
        if ($value === null) {
            return;
        }

        Assert::true($value >= $min && $value < $max, sprintf('The %s rule of %s %s is %s, expected no value or [%s, %s).', $record, $slo, $objective, $value, $min, $max));
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
        $firstSeen = $this->prometheus->sample(sprintf('min(min_over_time(timestamp(%s{slo="%s"})[1d:5s]))', self::EVENTS_METRIC, $eventsSlo));
        Assert::notNull($firstSeen, sprintf('%s has no %s events.', $eventsSlo, self::EVENTS_METRIC));
        $instant = $firstSeen - 10;
        Assert::same(
            $this->prometheus->sample(sprintf('max(up{service="%s"})', $service), $instant),
            1.0,
            'no idle instant before the first event; run on a fresh stack',
        );

        $rule = $this->rule($record, $slo, $objective);
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
        $this->remembered[$this->key($record, $slo, $objective)] = $this->valueOf($this->rule($record, $slo, $objective));
    }

    /**
     * @param non-empty-string $record
     */
    #[Then('the :record rule value of :slo :objective is within :tolerance of the remembered one')]
    public function theRuleValueIsWithinOfTheRememberedOne(string $record, string $slo, string $objective, float $tolerance): void
    {
        $remembered = $this->remembered($record, $slo, $objective);
        $value = $this->valueOf($this->rule($record, $slo, $objective));
        Assert::lessThanEq(abs($value - $remembered), $tolerance, sprintf('The %s rule of %s %s moved from %s to %s.', $record, $slo, $objective, $remembered, $value));
    }

    /**
     * @param non-empty-string $record
     */
    #[Then('the :record rule value of :slo :objective is below the remembered one')]
    public function theRuleValueIsBelowTheRememberedOne(string $record, string $slo, string $objective): void
    {
        $remembered = $this->remembered($record, $slo, $objective);
        $rule = $this->rule($record, $slo, $objective);
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
        $rule = $this->lastRule ?? throw new LogicException('Check a rule first.');
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
        $current = $this->rule('slo:sli:ratio', $slo, $objective)->labels['revision']
            ?? throw new LogicException(sprintf('The slo:sli:ratio rule of %s %s has no revision label.', $slo, $objective));
        $database = self::GATEWAY_METRICS_DATABASE;
        $key = $this->storage->counterKey($database, self::EVENTS_METRIC);

        $source = null;
        foreach ($this->storage->fields($database, $key) as $field) {
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
        $this->storage->increment($database, $key, $copy, 0);
        $series = sprintf('%s{slo="%s",objective="%s",revision="%s"} == 0', self::EVENTS_METRIC, $slo, $objective, $revision);
        Wait::until(
            fn (): bool => $this->prometheus->hasSeries($series),
            self::RETRY_SECONDS,
            sprintf('Prometheus did not scrape %s in time.', $series),
        );
        $this->storage->increment($database, $key, $copy, $count);
    }

    #[Then(':query has no series')]
    public function hasNoSeries(string $query): void
    {
        Assert::false($this->prometheus->hasSeries($query), sprintf('%s has a series.', $query));
    }

    /**
     * @param non-empty-string $record
     */
    private function rule(string $record, string $slo, string $objective): RecordingRule
    {
        return $this->lastRule = $this->prometheus->recordingRule($record, $slo, $objective);
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

    private function key(string $record, string $slo, string $objective): string
    {
        return $record . "\0" . $slo . "\0" . $objective;
    }

    private function utc(float $unix): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) $unix);
    }
}
