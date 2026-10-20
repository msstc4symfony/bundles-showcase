<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\LogLine;
use App\E2e\Client\LokiClient;
use App\E2e\Wait;
use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Hook\BeforeScenario;
use Behat\Step\Then;
use Behat\Step\When;
use LogicException;
use Webmozart\Assert\Assert;

final class WorkerResetContext implements Context
{
    private const string UNKNOWN_ORDER = '0190e0f6-0000-7000-8000-00000000ffff';

    private OrderContext $orders;

    private LokiClient $loki;

    private float $startedAt;

    /** @var array<string, non-empty-string> readable label => trace id sent by this scenario */
    private array $traces = [];

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $this->orders = OrderContext::from($scope);
        $this->loki = new LokiClient();
        $this->startedAt = microtime(true) - 5;
    }

    #[When('I send :count order lookups with traces :first to :last')]
    public function iSendOrderLookups(int $count, string $first, string $last): void
    {
        $prefix = (string) preg_replace('/\d+$/', '', $first);
        Assert::same($last, $prefix . $count, 'The label range must run from <prefix>1 to <prefix><count>.');

        for ($i = 1; $i <= $count; $i++) {
            $this->orders->gateway->showOrder(self::UNKNOWN_ORDER, $this->traceHeaders($prefix . $i));
        }
    }

    #[When('I create orders with traces :first, :second and :third')]
    public function iCreateOrdersWithTraces(string $first, string $second, string $third): void
    {
        foreach ([$first, $second, $third] as $readable) {
            Assert::same($this->orders->gateway->createOrder(5000, headers: $this->traceHeaders($readable))->status, 201);
        }
    }

    #[Then('within :seconds seconds the gateway logs show :count distinct runtime ids for those traces')]
    public function theGatewayLogsShowDistinctRuntimeIds(int $seconds, int $count): void
    {
        $runtimeIds = [];

        Wait::until(
            function () use (&$runtimeIds): bool {
                $runtimeIds = [];
                foreach ($this->traces as $traceId) {
                    $perTrace = $this->runtimeIdsOf('gateway', $traceId);
                    if (count($perTrace) !== 1) {
                        return false;
                    }

                    $runtimeIds[] = $perTrace[0];
                }

                return true;
            },
            $seconds,
            'Not every lookup produced gateway logs with exactly one runtime id.',
        );

        Assert::count(array_unique($runtimeIds), $count);
    }

    #[Then('no gateway log line of the :readable runtime carries another trace id')]
    public function noGatewayLineOfTheRuntimeCarriesAnotherTraceId(string $readable): void
    {
        $this->assertRuntimeIsolated('gateway', $readable);
    }

    #[Then('within :seconds seconds no :service log line of the :readable runtime carries another trace id')]
    public function withinNoLineOfTheRuntimeCarriesAnotherTraceId(int $seconds, string $service, string $readable): void
    {
        $this->traceOf($readable);
        // Every trace of the scenario must reach Loki first: a leak into a later message is only visible once it has.
        Wait::until(
            fn (): bool => array_filter($this->traces, fn (string $trace): bool => $this->runtimeIdsOf($service, $trace) === []) === [],
            $seconds,
            sprintf('Not every trace of this scenario reached the "%s" logs in time.', $service),
        );

        $this->assertRuntimeIsolated($service, $readable);
    }

    /**
     * @return array{traceparent: non-empty-string}
     */
    private function traceHeaders(string $readable): array
    {
        $traceId = bin2hex(random_bytes(16));
        $this->traces[$readable] = $traceId;

        return ['traceparent' => sprintf('00-%s-%s-01', $traceId, bin2hex(random_bytes(8)))];
    }

    /**
     * @return non-empty-string
     */
    private function traceOf(string $readable): string
    {
        return $this->traces[$readable] ?? throw new LogicException(sprintf('Send the "%s" trace first.', $readable));
    }

    private function assertRuntimeIsolated(string $service, string $readable): void
    {
        $traceId = $this->traceOf($readable);
        $runtimeIds = $this->runtimeIdsOf($service, $traceId);
        Assert::notEmpty($runtimeIds, sprintf('No "%s" logs for "%s".', $service, $readable));

        foreach ($runtimeIds as $runtimeId) {
            foreach ($this->loki->linesWhere('runtime_id', $runtimeId, $this->startedAt) as $line) {
                Assert::same($line->traceId(), $traceId, sprintf('Runtime %s of "%s" logged "%s".', $runtimeId, $readable, $line->message));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function runtimeIdsOf(string $service, string $traceId): array
    {
        $lines = array_filter(
            $this->loki->linesWhere('trace_id', $traceId, $this->startedAt),
            static fn (LogLine $line): bool => $line->service === $service && $line->runtimeId() !== null,
        );

        return array_values(array_unique(array_map(static fn (LogLine $line): string => (string) $line->runtimeId(), $lines)));
    }
}
