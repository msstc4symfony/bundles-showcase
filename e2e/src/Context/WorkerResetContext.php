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
use Webmozart\Assert\Assert;

final class WorkerResetContext implements Context
{
    private const string REQUEST_ID_HEADER = 'Request-Id';

    private const string UNKNOWN_ORDER = '0190e0f6-0000-7000-8000-00000000ffff';

    private OrderContext $orders;

    private LokiClient $loki;

    private float $startedAt;

    /** @var list<string> run-scoped request ids sent by this scenario */
    private array $requestIds = [];

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $this->orders = OrderContext::from($scope);
        $this->loki = new LokiClient();
        $this->startedAt = microtime(true) - 5;
    }

    #[When('I send :count order lookups with request ids :first to :last')]
    public function iSendOrderLookups(int $count, string $first, string $last): void
    {
        $prefix = (string) preg_replace('/\d+$/', '', $first);
        Assert::same($last, $prefix . $count, 'The id range must run from <prefix>1 to <prefix><count>.');

        for ($i = 1; $i <= $count; $i++) {
            $requestId = RunScoped::id($prefix . $i);
            $this->requestIds[] = $requestId;
            $this->orders->gateway->showOrder(self::UNKNOWN_ORDER, [self::REQUEST_ID_HEADER => $requestId]);
        }
    }

    #[When('I create orders with request ids :first, :second and :third')]
    public function iCreateOrdersWithRequestIds(string $first, string $second, string $third): void
    {
        foreach ([$first, $second, $third] as $readable) {
            $requestId = RunScoped::id($readable);
            $this->requestIds[] = $requestId;
            Assert::same($this->orders->gateway->createOrder(5000, headers: [self::REQUEST_ID_HEADER => $requestId])->status, 201);
        }
    }

    #[Then('within :seconds seconds the gateway logs show :count distinct runtime ids for those request ids')]
    public function theGatewayLogsShowDistinctRuntimeIds(int $seconds, int $count): void
    {
        $runtimeIds = [];

        Wait::until(
            function () use (&$runtimeIds): bool {
                $runtimeIds = [];
                foreach ($this->requestIds as $requestId) {
                    $perRequest = $this->runtimeIdsOf('gateway', $requestId);
                    if (count($perRequest) !== 1) {
                        return false;
                    }

                    $runtimeIds[] = $perRequest[0];
                }

                return true;
            },
            $seconds,
            'Not every lookup produced gateway logs with exactly one runtime id.',
        );

        Assert::count(array_unique($runtimeIds), $count);
    }

    #[Then('no gateway log line of the :readable runtime carries another request id')]
    public function noGatewayLineOfTheRuntimeCarriesAnotherRequestId(string $readable): void
    {
        $this->assertRuntimeIsolated('gateway', $readable);
    }

    #[Then('within :seconds seconds no :service log line of the :readable runtime carries another request id')]
    public function withinNoLineOfTheRuntimeCarriesAnotherRequestId(int $seconds, string $service, string $readable): void
    {
        Wait::until(
            fn (): bool => $this->runtimeIdsOf($service, RunScoped::id($readable)) !== [],
            $seconds,
            sprintf('No "%s" logs for "%s".', $service, $readable),
        );

        $this->assertRuntimeIsolated($service, $readable);
    }

    private function assertRuntimeIsolated(string $service, string $readable): void
    {
        $requestId = RunScoped::id($readable);
        $runtimeIds = $this->runtimeIdsOf($service, $requestId);
        Assert::notEmpty($runtimeIds, sprintf('No "%s" logs for "%s".', $service, $readable));

        foreach ($runtimeIds as $runtimeId) {
            foreach ($this->loki->linesWhere('runtime_id', $runtimeId, $this->startedAt) as $line) {
                Assert::same($line->requestId(), $requestId, sprintf('Runtime %s of "%s" logged "%s".', $runtimeId, $readable, $line->message));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function runtimeIdsOf(string $service, string $requestId): array
    {
        $lines = array_filter(
            $this->loki->linesFor($requestId, $this->startedAt),
            static fn (LogLine $line): bool => $line->service === $service && $line->runtimeId() !== null,
        );

        return array_values(array_unique(array_map(static fn (LogLine $line): string => (string) $line->runtimeId(), $lines)));
    }
}
