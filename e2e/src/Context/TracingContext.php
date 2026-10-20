<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\LogLine;
use App\E2e\Client\LokiClient;
use App\E2e\Wait;
use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\BeforeScenario;
use Behat\Step\Then;
use Behat\Step\When;
use LogicException;
use Webmozart\Assert\Assert;

final class TracingContext implements Context
{
    // logic-bundle's LoggingMiddleware writes "Start|Finish|Fail application action <name>" inside the Action's span.
    private const string ACTION_LOG_MARKER = 'application action';

    private OrderContext $orders;

    private LokiClient $loki;

    private float $startedAt;

    private ?string $traceId = null;

    private ?string $clientSpanId = null;

    /** @var non-empty-string|null last poll's result, for the timeout message */
    private ?string $missingHop = null;

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $this->orders = OrderContext::from($scope);
        $this->loki = new LokiClient();
        // Small margin: container clocks and the runner clock may differ slightly.
        $this->startedAt = microtime(true) - 5;
    }

    #[When('I create an order for :amount with a traceparent')]
    public function iCreateAnOrderWithATraceparent(int $amount): void
    {
        $this->traceId = bin2hex(random_bytes(16));
        $this->clientSpanId = bin2hex(random_bytes(8));
        $this->orders->recordCreation($this->orders->gateway->createOrder(
            $amount,
            headers: ['traceparent' => sprintf('00-%s-%s-01', $this->traceId, $this->clientSpanId)],
        ));
    }

    #[Then('within :seconds seconds logs with that trace id come from:')]
    public function logsWithThatTraceIdComeFrom(int $seconds, TableNode $services): void
    {
        $traceId = $this->traceId ?? throw new LogicException('Send a traceparent first.');
        $expected = $this->serviceNames($services);

        Wait::until(
            function () use ($traceId, $expected): bool {
                $seen = array_values(array_unique(array_map(
                    static fn (LogLine $line): string => $line->service,
                    $this->loki->linesWhere('trace_id', $traceId, $this->startedAt),
                )));
                sort($seen);

                return array_values(array_intersect($expected, $seen)) === $expected;
            },
            $seconds,
            sprintf('Logs for trace id %s did not reach every process in time.', $traceId),
        );
    }

    #[Then('within :seconds seconds the logs of that trace link the client, gateway, orders, its Action, billing-worker and orders-worker')]
    public function theLogsOfThatTraceFormTheCallTree(int $seconds): void
    {
        $traceId = $this->traceId ?? throw new LogicException('Send a traceparent first.');
        $clientSpanId = $this->clientSpanId ?? throw new LogicException('Send a traceparent first.');
        $this->missingHop = null;

        Wait::until(
            function () use ($traceId, $clientSpanId): bool {
                $lines = $this->loki->linesWhere('trace_id', $traceId, $this->startedAt);
                $this->missingHop = $this->missingCallTreeHop($lines, $clientSpanId);

                return $this->missingHop === null;
            },
            $seconds,
            fn (): string => sprintf(
                'Logs for trace id %s have no "%s" hop under its caller.',
                $traceId,
                $this->missingHop ?? '?',
            ),
        );
    }

    #[Then('the response header :name continues that trace')]
    public function theResponseHeaderContinuesThatTrace(string $name): void
    {
        $traceId = $this->traceId ?? throw new LogicException('Send a traceparent first.');
        $parts = explode('-', $this->orders->scenario->lastResponse()->header($name) ?? '');

        Assert::count($parts, 4, sprintf('"%s" is not a traceparent-shaped header.', $name));
        Assert::same($parts[1], $traceId);
        Assert::notSame($parts[2], $this->clientSpanId, 'The server must answer with its own span.');
    }

    #[Then('the response has a :name header with a new trace id')]
    public function theResponseHasAHeaderWithANewTraceId(string $name): void
    {
        $parts = explode('-', $this->orders->scenario->lastResponse()->header($name) ?? '');

        Assert::count($parts, 4, sprintf('"%s" is not a traceparent-shaped header.', $name));
        Assert::regex($parts[1], '/^[0-9a-f]{32}$/');
        $this->traceId = $parts[1];
    }

    #[Then('the :service logs of that trace have caller :caller')]
    public function theLogsOfThatTraceHaveCaller(string $service, string $caller): void
    {
        $traceId = $this->traceId ?? throw new LogicException('Send a traceparent first.');
        $lines = array_filter(
            $this->loki->linesWhere('trace_id', $traceId, $this->startedAt),
            static fn (LogLine $line): bool => $line->service === $service,
        );

        Assert::notEmpty($lines, sprintf('No "%s" logs for trace id %s.', $service, $traceId));
        foreach ($lines as $line) {
            Assert::same($line->caller(), $caller, sprintf('"%s" log "%s" has the wrong caller.', $service, $line->message));
        }
    }

    /**
     * Each hop's parent_span_id must be a span_id logged by the hop before it.
     *
     * @param list<LogLine> $lines
     *
     * @return non-empty-string|null the first hop without such a log line; null when the tree is complete
     */
    private function missingCallTreeHop(array $lines, string $clientSpanId): ?string
    {
        $hops = [
            'gateway' => static fn (LogLine $line): bool => $line->service === 'gateway',
            'orders request' => static fn (LogLine $line): bool => $line->service === 'orders' && !self::isActionLog($line),
            'orders Action' => static fn (LogLine $line): bool => $line->service === 'orders' && self::isActionLog($line),
            'billing-worker' => static fn (LogLine $line): bool => $line->service === 'billing-worker',
            'orders-worker' => static fn (LogLine $line): bool => $line->service === 'orders-worker',
        ];
        $parents = [$clientSpanId];
        foreach ($hops as $hop => $belongs) {
            $spans = [];
            foreach ($lines as $line) {
                $span = $line->spanId();
                if ($span !== null && $belongs($line) && in_array($line->parentSpanId(), $parents, true)) {
                    $spans[$span] = true;
                }
            }

            if ($spans === []) {
                return $hop;
            }

            $parents = array_map(strval(...), array_keys($spans));
        }

        return null;
    }

    private static function isActionLog(LogLine $line): bool
    {
        return str_contains($line->message, self::ACTION_LOG_MARKER);
    }

    /**
     * @return list<string> sorted
     */
    private function serviceNames(TableNode $services): array
    {
        $names = [];
        foreach ($services->getRows() as $row) {
            Assert::count($row, 1, 'Each row names exactly one service.');
            $names[] = (string) $row[0];
        }

        Assert::notEmpty($names, 'Name at least one service.');
        sort($names);

        return $names;
    }
}
