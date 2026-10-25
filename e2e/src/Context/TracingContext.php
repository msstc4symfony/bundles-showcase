<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\LogLine;
use App\E2e\Client\LokiClient;
use App\E2e\Wait;
use Behat\Behat\Context\Context;
use Behat\Behat\Context\Environment\InitializedContextEnvironment;
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
        $this->orders->recordCreation($this->orders->gateway->createOrder(
            $amount,
            headers: ['traceparent' => $this->newTraceParent()],
        ));
    }

    #[When('I send the baggage probe with a traceparent')]
    public function iSendTheBaggageProbeWithATraceparent(): void
    {
        $response = $this->orders->gateway->probeBaggage([
            'traceparent' => $this->newTraceParent(),
            'baggage' => 'tenant=e2e',
        ]);

        Assert::same(
            $response->body,
            ['direct' => 200, 'via_orders' => 200],
            'The baggage probe did not reach the echo.',
        );
    }

    #[Then('within :seconds seconds the baggage echo gets that trace on :first and :second without baggage')]
    public function theBaggageEchoGetsThatTraceWithoutBaggage(int $seconds, string $first, string $second): void
    {
        $traceId = $this->traceId();
        $paths = [$first, $second];
        $lines = [];

        Wait::until(
            function () use ($traceId, $paths, &$lines): bool {
                $lines = array_values(array_filter(
                    $this->loki->linesOfTrace($traceId, $this->startedAt),
                    static fn (LogLine $line): bool => $line->service === 'baggage-echo',
                ));
                $seen = array_filter(array_map(static fn (LogLine $line): ?string => $line->path(), $lines), is_string(...));

                return array_diff($paths, $seen) === [];
            },
            $seconds,
            sprintf('The baggage echo did not log trace id %s on %s and %s in time.', $traceId, $first, $second),
        );

        foreach ($lines as $line) {
            Assert::null($line->baggage(), sprintf('The baggage echo got baggage on "%s".', $line->message));
        }
    }

    #[Then('within :seconds seconds logs with that trace id come from:')]
    public function logsWithThatTraceIdComeFrom(int $seconds, TableNode $services): void
    {
        $traceId = $this->traceId();
        $expected = $this->serviceNames($services);

        Wait::until(
            function () use ($traceId, $expected): bool {
                $seen = array_values(array_unique(array_map(
                    static fn (LogLine $line): string => $line->service,
                    $this->loki->linesOfTrace($traceId, $this->startedAt),
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
        $traceId = $this->traceId();
        $clientSpanId = $this->clientSpanId ?? throw new LogicException('Send a traceparent first.');
        $this->missingHop = null;

        Wait::until(
            function () use ($traceId, $clientSpanId): bool {
                $lines = $this->loki->linesOfTrace($traceId, $this->startedAt);
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

    #[Then('within :seconds seconds the :service logs of that trace carry the tracing ids at the top level, not in extra')]
    public function theLogsCarryTracingIdsAtTheTopLevel(int $seconds, string $service): void
    {
        $traceId = $this->traceId();
        $lines = [];

        Wait::until(
            function () use ($traceId, $service, &$lines): bool {
                $lines = array_values(array_filter(
                    $this->loki->linesOfTrace($traceId, $this->startedAt),
                    static fn (LogLine $line): bool => $line->service === $service,
                ));

                return $lines !== [];
            },
            $seconds,
            sprintf('No "%s" logs for trace id %s in time.', $service, $traceId),
        );

        foreach ($lines as $line) {
            foreach (['trace_id', 'span_id', 'parent_span_id'] as $key) {
                Assert::false($line->hasExtraKey($key), sprintf('"%s" log "%s" still has "%s" in extra.', $service, $line->message, $key));
            }

            Assert::true($line->hasTopLevelKey('parent_span_id'), sprintf('"%s" log "%s" has no top-level "parent_span_id".', $service, $line->message));
            Assert::regex($line->spanId() ?? '', LogLine::SPAN_ID_PATTERN, sprintf('"%s" log "%s" has no span id.', $service, $line->message));
            Assert::nullOrRegex($line->parentSpanId(), LogLine::SPAN_ID_PATTERN, sprintf('"%s" log "%s" has a malformed parent span id.', $service, $line->message));
        }
    }

    public function traceId(): string
    {
        return $this->traceId ?? throw new LogicException('Send a traceparent first.');
    }

    /**
     * @return list<LogLine>
     */
    public function linesOfThatTrace(): array
    {
        return $this->loki->linesOfTrace($this->traceId(), $this->startedAt);
    }

    #[Then('within :seconds seconds the :service logs contain :text')]
    public function theLogsContain(int $seconds, string $service, string $text): void
    {
        Wait::until(
            fn (): bool => $this->loki->linesOfServiceContaining($service, $text, $this->startedAt) !== [],
            $seconds,
            sprintf('The "%s" logs did not contain "%s" in time.', $service, $text),
        );
    }

    public function clientSpanId(): string
    {
        return $this->clientSpanId ?? throw new LogicException('Send a traceparent first.');
    }

    public function startedAt(): float
    {
        return $this->startedAt;
    }

    public static function from(BeforeScenarioScope $scope): self
    {
        $environment = $scope->getEnvironment();
        Assert::isInstanceOf($environment, InitializedContextEnvironment::class);

        return $environment->getContext(self::class);
    }

    #[Then('the response header :name continues that trace')]
    public function theResponseHeaderContinuesThatTrace(string $name): void
    {
        $traceId = $this->traceId();
        $parts = $this->traceResponseParts($name);

        Assert::same($parts[1], $traceId);
        Assert::notSame($parts[2], $this->clientSpanId, 'The server must answer with its own span.');
    }

    #[Then('the response has a :name header with a new trace id')]
    public function theResponseHasAHeaderWithANewTraceId(string $name): void
    {
        $this->traceId = $this->traceResponseParts($name)[1];
    }

    #[Then('the :service logs of that trace have caller :caller')]
    public function theLogsOfThatTraceHaveCaller(string $service, string $caller): void
    {
        $traceId = $this->traceId();
        $lines = array_filter(
            $this->loki->linesOfTrace($traceId, $this->startedAt),
            static fn (LogLine $line): bool => $line->service === $service,
        );

        Assert::notEmpty($lines, sprintf('No "%s" logs for trace id %s.', $service, $traceId));
        foreach ($lines as $line) {
            Assert::same($line->caller(), $caller, sprintf('"%s" log "%s" has the wrong caller.', $service, $line->message));
        }
    }

    /**
     * A trace the gateway started has no parent: a gateway worker that kept the previous request's context
     * would log that request's trace and span as parent instead.
     */
    #[Then('the :service logs of that trace start it')]
    public function theLogsOfThatTraceStartIt(string $service): void
    {
        $traceId = $this->traceId();
        $lines = array_filter(
            $this->loki->linesOfTrace($traceId, $this->startedAt),
            static fn (LogLine $line): bool => $line->service === $service,
        );

        Assert::notEmpty($lines, sprintf('No "%s" logs for trace id %s.', $service, $traceId));
        foreach ($lines as $line) {
            Assert::null($line->parentSpanId(), sprintf('"%s" log "%s" has a parent span in a trace it started.', $service, $line->message));
        }
    }

    /**
     * @return array{non-empty-string, non-empty-string, non-empty-string, non-empty-string} version, trace id, span id, flags
     */
    private function traceResponseParts(string $name): array
    {
        $value = $this->orders->scenario->lastResponse()->header($name) ?? '';
        Assert::regex($value, '/^00-(?!0{32})[0-9a-f]{32}-(?!0{16})[0-9a-f]{16}-[0-9a-f]{2}$/D', sprintf('"%s: %s" is not a valid traceparent-shaped value.', $name, $value));

        [$version, $traceId, $spanId, $flags] = explode('-', $value);
        Assert::stringNotEmpty($version);
        Assert::stringNotEmpty($traceId);
        Assert::stringNotEmpty($spanId);
        Assert::stringNotEmpty($flags);

        return [$version, $traceId, $spanId, $flags];
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

    private function newTraceParent(): string
    {
        $this->traceId = bin2hex(random_bytes(16));
        $this->clientSpanId = bin2hex(random_bytes(8));

        return sprintf('00-%s-%s-01', $this->traceId, $this->clientSpanId);
    }
}
