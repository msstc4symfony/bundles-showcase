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
use LogicException;
use Webmozart\Assert\Assert;

final class ProfilingContext implements Context
{
    private TracingContext $tracing;

    private LokiClient $loki;

    private ?LogLine $span = null;

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $this->tracing = TracingContext::from($scope);
        $this->loki = new LokiClient();
    }

    #[Then('within :seconds seconds the :service logs of that trace have a profiling span matching :pattern')]
    public function theLogsHaveAProfilingSpanMatching(int $seconds, string $service, string $pattern): void
    {
        Assert::stringNotEmpty($pattern);
        $traceId = $this->tracing->traceId();

        Wait::until(
            function () use ($traceId, $service, $pattern): bool {
                foreach ($this->loki->linesOfTrace($traceId, $this->tracing->startedAt()) as $line) {
                    if ($line->service === $service && $line->channel() === 'profiling' && preg_match($pattern, $line->message) === 1) {
                        $this->span = $line;

                        return true;
                    }
                }

                return false;
            },
            $seconds,
            sprintf('"%s" logged no profiling span matching %s for trace id %s in time.', $service, $pattern, $traceId),
        );
    }

    #[Then('that span has a trace span id of its own')]
    public function thatSpanHasATraceSpanIdOfItsOwn(): void
    {
        $span = $this->span();
        $own = $span->contextString('trace_span_id');
        $around = $span->spanId();
        Assert::regex($own ?? '', LogLine::SPAN_ID_PATTERN, sprintf('Span "%s" has no trace_span_id.', $span->message));
        Assert::regex($around ?? '', LogLine::SPAN_ID_PATTERN, sprintf('Span "%s" has no span_id around it.', $span->message));
        Assert::notSame($own, $around, 'A span record names the span around it, not its own.');
        Assert::same($span->contextString('trace_parent_span_id'), $around, sprintf('Span "%s": trace_parent_span_id is not the span around it.', $span->message));
    }

    #[Then('that span is detached and runs in the trace span around it')]
    public function thatSpanIsDetached(): void
    {
        $span = $this->span();
        Assert::regex($span->spanId() ?? '', LogLine::SPAN_ID_PATTERN, sprintf('Detached span "%s" has no span_id around it.', $span->message));
        Assert::false($span->hasContextKey('trace_parent_span_id'), sprintf('Detached span "%s" has a trace parent span.', $span->message));
        Assert::true($span->contextBool('profiling_detached'), sprintf('Span "%s" is not detached.', $span->message));
        Assert::false($span->hasContextKey('trace_span_id'), sprintf('Detached span "%s" has a trace span of its own.', $span->message));
    }

    private function span(): LogLine
    {
        return $this->span ?? throw new LogicException('Look for a profiling span first.');
    }
}
