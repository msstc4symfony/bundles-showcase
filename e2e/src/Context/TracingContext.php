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

final class TracingContext implements Context
{
    private const string REQUEST_ID_HEADER = 'Request-Id';

    private OrderContext $orders;

    private LokiClient $loki;

    private float $startedAt;

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $this->orders = OrderContext::from($scope);
        $this->loki = new LokiClient();
        // Small margin: container clocks and the runner clock may differ slightly.
        $this->startedAt = microtime(true) - 5;
    }

    #[When('I create an order for :amount with request id :requestId')]
    public function iCreateAnOrderWithRequestId(int $amount, string $requestId): void
    {
        $this->orders->recordCreation($this->orders->gateway->createOrder(
            $amount,
            headers: [self::REQUEST_ID_HEADER => RunScoped::id($requestId)],
        ));
    }

    #[Then('the response header :name is the request id :requestId')]
    public function theResponseHeaderIsTheRequestId(string $name, string $requestId): void
    {
        Assert::same($this->orders->scenario->lastResponse()->header($name), RunScoped::id($requestId));
    }

    #[Then('the response has a non-empty :name header')]
    public function theResponseHasANonEmptyHeader(string $name): void
    {
        Assert::stringNotEmpty($this->orders->scenario->lastResponse()->header($name));
    }

    #[Then('within :seconds seconds logs with request id :requestId come from :first, :second, :third and :fourth')]
    public function logsComeFrom(int $seconds, string $requestId, string $first, string $second, string $third, string $fourth): void
    {
        $expected = [$first, $second, $third, $fourth];
        sort($expected);
        $seen = [];

        Wait::until(
            function () use ($requestId, $expected, &$seen): bool {
                $seen = array_values(array_unique(array_map(
                    static fn (LogLine $line): string => $line->service,
                    $this->loki->linesFor(RunScoped::id($requestId), $this->startedAt),
                )));
                sort($seen);

                return array_values(array_intersect($expected, $seen)) === $expected;
            },
            $seconds,
            sprintf('Logs for request id "%s" did not reach every process in time.', $requestId),
        );
    }

    #[Then('the :service logs for request id :requestId have request from :caller')]
    public function theLogsHaveRequestFrom(string $service, string $requestId, string $caller): void
    {
        $lines = array_filter(
            $this->loki->linesFor(RunScoped::id($requestId), $this->startedAt),
            static fn (LogLine $line): bool => $line->service === $service,
        );

        Assert::notEmpty($lines, sprintf('No "%s" logs for request id "%s".', $service, $requestId));
        foreach ($lines as $line) {
            Assert::same($line->requestFrom(), $caller, sprintf('"%s" log "%s" has the wrong request_from.', $service, $line->message));
        }
    }
}
