<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\GatewayClient;
use App\E2e\Client\LogLine;
use App\E2e\Client\LokiClient;
use App\E2e\Client\TempoClient;
use App\E2e\Client\TraceParent;
use App\E2e\Wait;
use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Hook\BeforeScenario;
use Behat\Step\Then;
use Behat\Step\When;
use Webmozart\Assert\Assert;

/**
 * New traces of gateway-sampled, each checked against the decision its trace id implies.
 */
final class SamplingContext implements Context
{
    // The rejection threshold of TRACING_SAMPLING_RATIO 0.5 (gateway-sampled in compose.yaml) is 2^55: a trace is
    // sampled when the low 56 bits of its id reach it.
    private const string THRESHOLD = '80000000000000';

    private const string SAMPLED_FLAGS = '03';

    private const string DROPPED_FLAGS = '02';

    private const array PATHS = ['/direct', '/via-orders'];

    private const array SERVICES = ['gateway-sampled', 'orders'];

    private TracingContext $tracing;

    private LokiClient $loki;

    private TempoClient $tempo;

    /** @var list<TraceParent> the traceresponse of each probe */
    private array $probes = [];

    /** @var list<string> last poll's result, for the timeout message */
    private array $problems = [];

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $this->tracing = TracingContext::from($scope);
        $this->loki = new LokiClient();
        $this->tempo = new TempoClient();
        $this->probes = [];
        $this->problems = [];
    }

    #[When('I send the baggage probe to the sampling gateway :count times without a traceparent')]
    public function iSendTheBaggageProbeToTheSamplingGateway(int $count): void
    {
        $url = getenv('SAMPLING_GATEWAY_URL');
        Assert::stringNotEmpty($url, 'SAMPLING_GATEWAY_URL is not set.');
        $gateway = new GatewayClient($url);

        for ($i = 0; $i < $count; $i++) {
            $response = $gateway->probeBaggage();
            Assert::same($response->body, ['direct' => 200, 'via_orders' => 200], 'The baggage probe did not reach the echo.');
            $this->probes[] = TraceParent::fromHeader($response->header('traceresponse'), 'traceresponse');
        }
    }

    #[Then('every traceresponse is flagged random, and sampled exactly when the trace id reaches the threshold')]
    public function everyTraceResponseIsFlaggedByItsTraceId(): void
    {
        Assert::notEmpty($this->probes, 'Send the probes first.');
        foreach ($this->probes as $probe) {
            Assert::same($probe->flags, $this->expectedFlags($probe->traceId), sprintf('Trace %s has the wrong flags in traceresponse.', $probe->traceId));
        }
    }

    #[Then('the probes started both sampled and dropped traces')]
    public function theProbesStartedBothSampledAndDroppedTraces(): void
    {
        Assert::notEmpty($this->sampledTraceIds(), 'No probe started a sampled trace.');
        Assert::notEmpty($this->droppedTraceIds(), 'No probe started a dropped trace.');
    }

    /**
     * The gateway sends /direct itself and /via-orders when it follows orders' redirect: both carry its trace headers.
     */
    #[Then('within :seconds seconds the baggage echo gets every trace with its flags, with :traceState in tracestate only when sampled')]
    public function theBaggageEchoGetsEveryTraceWithItsFlags(int $seconds, string $traceState): void
    {
        Wait::until(
            function () use ($traceState): bool {
                $lines = $this->loki->linesOfServiceContaining('baggage-echo', 'Baggage echo received', $this->tracing->startedAt());
                $this->problems = $this->echoProblems($lines, $traceState);

                return $this->problems === [];
            },
            $seconds,
            fn (): string => sprintf('The baggage echo did not get every trace as its id implies: %s.', implode('; ', $this->problems)),
        );
    }

    /**
     * orders continues each trace: its spans reach Tempo under the gateway's only when the gateway sampled it.
     */
    #[Then('within :seconds seconds Tempo has every sampled trace with spans of gateway-sampled and orders, and none of the dropped')]
    public function tempoHasOnlyTheSampledTraces(int $seconds): void
    {
        $missing = $this->sampledTraceIds();
        Assert::notEmpty($missing, 'No sampled trace to look for.');
        Wait::until(
            function () use (&$missing): bool {
                $missing = array_values(array_filter($missing, fn (string $traceId): bool => !$this->isInTempo($traceId)));

                return $missing === [];
            },
            $seconds,
            static function () use (&$missing): string {
                return sprintf('Tempo has no spans of %s for the sampled traces %s.', implode(', ', self::SERVICES), implode(', ', $missing));
            },
        );

        // Every sampled trace has been exported by now, so a dropped one would have been as well.
        foreach ($this->droppedTraceIds() as $traceId) {
            $lookup = $this->tempo->trace($traceId);
            Assert::true($lookup->notFound, sprintf('Tempo did not report the dropped trace %s as unknown (%s).', $traceId, $lookup->reason));
        }
    }

    private function isInTempo(string $traceId): bool
    {
        return $this->tempo->trace($traceId)->tree?->hasSpansOf(self::SERVICES) ?? false;
    }

    /**
     * @param list<LogLine> $lines
     *
     * @return list<string>
     */
    private function echoProblems(array $lines, string $traceState): array
    {
        $problems = [];
        foreach ($this->probes as $probe) {
            $expectedState = self::sampledById($probe->traceId) ? $traceState : null;
            $seen = [];
            foreach ($lines as $line) {
                if ($line->traceId() !== $probe->traceId) {
                    continue;
                }

                $seen[] = $line->path();
                $flags = TraceParent::fromHeader($line->traceParent())->flags;
                if ($flags !== $this->expectedFlags($probe->traceId) || $line->traceState() !== $expectedState) {
                    $problems[] = sprintf('%s on %s got flags %s and tracestate %s', $probe->traceId, $line->path() ?? '?', $flags, $line->traceState() ?? 'none');
                }
            }

            $unseen = array_diff(self::PATHS, $seen);
            if ($unseen !== []) {
                $problems[] = sprintf('%s not yet on %s', $probe->traceId, implode(', ', $unseen));
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function sampledTraceIds(): array
    {
        return $this->traceIds(true);
    }

    /**
     * @return list<string>
     */
    private function droppedTraceIds(): array
    {
        return $this->traceIds(false);
    }

    /**
     * @return list<string>
     */
    private function traceIds(bool $sampled): array
    {
        return array_values(array_map(
            static fn (TraceParent $probe): string => $probe->traceId,
            array_filter($this->probes, static fn (TraceParent $probe): bool => self::sampledById($probe->traceId) === $sampled),
        ));
    }

    private function expectedFlags(string $traceId): string
    {
        return self::sampledById($traceId) ? self::SAMPLED_FLAGS : self::DROPPED_FLAGS;
    }

    private static function sampledById(string $traceId): bool
    {
        return strcmp(substr($traceId, -strlen(self::THRESHOLD)), self::THRESHOLD) >= 0;
    }
}
