<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\LogLine;
use App\E2e\Client\TempoClient;
use App\E2e\Client\TraceLookup;
use App\E2e\Wait;
use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\BeforeScenario;
use Behat\Step\Then;
use RuntimeException;

/**
 * Spans exported by tracing-bridge-otlp, read back from Tempo. Every check polls: the processes of one trace export
 * when their own unit of work ends, so Tempo fills in over a few seconds.
 */
final class TraceExportContext implements Context
{
    private const array SERVICES = ['billing', 'gateway', 'orders'];

    // One try of the recovery step: long enough for Alloy and Tempo to show a trace, short enough to retry.
    private const float RECOVERY_TRY_SECONDS = 10.0;

    private const int CREATED = 201;

    private TracingContext $tracing;

    private OrderContext $orders;

    private TempoClient $tempo;

    private ?TraceLookup $lookup = null;

    /** @var list<string> */
    private array $problems = [];

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $this->tracing = TracingContext::from($scope);
        $this->orders = OrderContext::from($scope);
        $this->tempo = new TempoClient();
        $this->lookup = null;
        $this->problems = [];
    }

    #[Then('within :seconds seconds Tempo has that trace with spans of:')]
    public function tempoHasThatTraceWithSpansOf(int $seconds, TableNode $services): void
    {
        $expected = array_map(static fn (array $row): string => (string) $row[0], $services->getRows());

        Wait::until(
            fn (): bool => $this->hasSpansOf($this->tracing->traceId(), $expected),
            $seconds,
            fn (): string => sprintf(
                'Tempo has no trace %s with spans of %s: %s.',
                $this->tracing->traceId(),
                implode(', ', $expected),
                $this->lastReason(),
            ),
        );
    }

    #[Then("within :seconds seconds every span of that trace in Tempo has its parent in the trace or the client's span")]
    public function everySpanHasItsParentInTheTrace(int $seconds): void
    {
        Wait::until(
            function (): bool {
                $this->lookup = $this->tempo->trace($this->tracing->traceId());
                $this->problems = $this->lookup->tree?->orphans([$this->tracing->clientSpanId()]) ?? ['no trace'];

                return $this->problems === [];
            },
            $seconds,
            fn (): string => sprintf('Spans whose parent is not in the trace: %s.', implode(', ', $this->problems)),
        );
    }

    #[Then('within :seconds seconds every span id in the logs of that trace is a span of it in Tempo')]
    public function everyLoggedSpanIdIsInTempo(int $seconds): void
    {
        Wait::until(
            function (): bool {
                $logged = array_filter(
                    array_map(
                        static fn (LogLine $line): ?string => $line->spanId(),
                        $this->tracing->linesOfThatTrace(),
                    ),
                    is_string(...),
                );
                $this->lookup = $this->tempo->trace($this->tracing->traceId());
                $this->problems = $logged === []
                    ? ['no log line with a span id']
                    : array_values(array_diff(array_unique($logged), $this->lookup->tree?->spanIds() ?? []));

                return $this->problems === [];
            },
            $seconds,
            fn (): string => sprintf('Logged span ids not in Tempo: %s.', implode(', ', $this->problems)),
        );
    }

    /**
     * Each process pauses exporting for 30 seconds after a failure: orders are sent until one trace makes it whole.
     * The last order's trace becomes "that trace" of the scenario.
     */
    #[Then('within :seconds seconds the trace of a new order reaches Tempo from every service')]
    public function theTraceOfANewOrderReachesTempo(int $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        $lastStatus = null;
        while (($left = $deadline - microtime(true)) > 0) {
            $traceParent = $this->tracing->newTraceParent();
            $lastStatus = $this->orders->gateway->createOrder(5000, headers: ['traceparent' => $traceParent])->status;
            $traceId = $this->tracing->traceId();
            $reached = $lastStatus === self::CREATED && Wait::within(
                fn (): bool => $this->hasSpansOf($traceId, self::SERVICES),
                min(self::RECOVERY_TRY_SECONDS, $left),
            );
            if ($reached) {
                return;
            }
        }

        throw new RuntimeException(sprintf('No new order reached Tempo from %s within %d seconds (last order: HTTP %s, last lookup: %s).', implode(', ', self::SERVICES), $seconds, $lastStatus ?? 'none', $this->lastReason()));
    }

    /**
     * @param list<string> $services
     */
    private function hasSpansOf(string $traceId, array $services): bool
    {
        $this->lookup = $this->tempo->trace($traceId);

        return $this->lookup->tree?->hasSpansOf($services) ?? false;
    }

    private function lastReason(): string
    {
        return $this->lookup->reason ?? 'no lookup';
    }
}
