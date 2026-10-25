<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\LogLine;
use App\E2e\Client\TempoClient;
use App\E2e\Client\TraceTree;
use App\E2e\Wait;
use Behat\Behat\Context\Context;
use Behat\Behat\Context\Environment\InitializedContextEnvironment;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\BeforeScenario;
use Behat\Step\Then;
use LogicException;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * Spans exported by tracing-bridge-otlp, read back from Tempo.
 */
final class TraceExportContext implements Context
{
    private const array SERVICES = ['billing', 'gateway', 'orders'];

    // One try of the recovery step: long enough for Alloy and Tempo to show a trace, short enough to retry.
    private const int RECOVERY_TRY_SECONDS = 10;

    private TracingContext $tracing;

    private OrderContext $orders;

    private TempoClient $tempo;

    private ?TraceTree $tree = null;

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $environment = $scope->getEnvironment();
        Assert::isInstanceOf($environment, InitializedContextEnvironment::class);
        $this->tracing = $environment->getContext(TracingContext::class);
        $this->orders = $environment->getContext(OrderContext::class);
        $this->tempo = new TempoClient();
        $this->tree = null;
    }

    #[Then('within :seconds seconds Tempo has that trace with spans of:')]
    public function tempoHasThatTraceWithSpansOf(int $seconds, TableNode $services): void
    {
        $traceId = $this->tracing->traceId();
        $expected = array_map(static fn (array $row): string => (string) $row[0], $services->getRows());
        sort($expected);

        Wait::until(
            fn (): bool => $this->traceHasSpansOf($traceId, $expected),
            $seconds,
            fn (): string => sprintf(
                'Tempo has no trace %s with spans of %s; it has: %s (%s).',
                $traceId,
                implode(', ', $expected),
                implode(', ', $this->tree?->services() ?? []),
                $this->tempo->lastMiss(),
            ),
        );
    }

    #[Then("every span of that trace in Tempo has its parent in the trace or the client's span as parent")]
    public function everySpanHasItsParentInTheTrace(): void
    {
        $orphans = $this->tree()->orphans([$this->tracing->clientSpanId()]);

        Assert::same($orphans, [], sprintf('Spans whose parent is not in the trace: %s.', implode(', ', $orphans)));
    }

    #[Then('the span ids of that trace in Tempo are the span ids of its logs')]
    public function theSpanIdsAreTheSpanIdsOfItsLogs(): void
    {
        $spanIds = $this->tree()->spanIds();
        $logged = array_values(array_unique(array_filter(
            array_map(static fn (LogLine $line): ?string => $line->spanId(), $this->tracing->linesOfThatTrace()),
            is_string(...),
        )));
        Assert::notEmpty($logged, 'No log line of that trace has a span id.');

        $missing = array_values(array_diff($logged, $spanIds));
        Assert::same($missing, [], sprintf('Span ids logged but not in Tempo: %s.', implode(', ', $missing)));
    }

    /**
     * Each process pauses exporting for 30 seconds after a failure: orders are sent until one trace makes it whole.
     */
    #[Then('within :seconds seconds the trace of a new order reaches Tempo from every service')]
    public function theTraceOfANewOrderReachesTempo(int $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $traceId = bin2hex(random_bytes(16));
            $this->orders->gateway->createOrder(5000, headers: ['traceparent' => sprintf('00-%s-%s-01', $traceId, bin2hex(random_bytes(8)))]);
            try {
                Wait::until(fn (): bool => $this->traceHasSpansOf($traceId, self::SERVICES), self::RECOVERY_TRY_SECONDS, 'not yet');

                return;
            } catch (RuntimeException) {
            }
        }

        throw new RuntimeException(sprintf('No new order reached Tempo from %s within %d seconds (%s).', implode(', ', self::SERVICES), $seconds, $this->tempo->lastMiss()));
    }

    /**
     * @param list<string> $expected sorted
     */
    private function traceHasSpansOf(string $traceId, array $expected): bool
    {
        $this->tree = $this->tempo->trace($traceId);

        return $this->tree?->hasSpansOf($expected) ?? false;
    }

    private function tree(): TraceTree
    {
        return $this->tree ?? throw new LogicException('Read the trace from Tempo first.');
    }
}
