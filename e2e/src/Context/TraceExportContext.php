<?php

declare(strict_types=1);

namespace App\E2e\Context;

use App\E2e\Client\LokiClient;
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
use Webmozart\Assert\Assert;

/**
 * Spans exported by tracing-bridge-otlp, read back from Tempo.
 */
final class TraceExportContext implements Context
{
    private TracingContext $tracing;

    private TempoClient $tempo;

    private LokiClient $loki;

    private ?TraceTree $tree = null;

    #[BeforeScenario]
    public function gatherContexts(BeforeScenarioScope $scope): void
    {
        $environment = $scope->getEnvironment();
        Assert::isInstanceOf($environment, InitializedContextEnvironment::class);
        $this->tracing = $environment->getContext(TracingContext::class);
        $this->tempo = new TempoClient();
        $this->loki = new LokiClient();
        $this->tree = null;
    }

    #[Then('within :seconds seconds Tempo has that trace with spans of:')]
    public function tempoHasThatTraceWithSpansOf(int $seconds, TableNode $services): void
    {
        $traceId = $this->tracing->traceId();
        $expected = array_map(static fn (array $row): string => (string) $row[0], $services->getRows());
        sort($expected);

        Wait::until(
            function () use ($traceId, $expected): bool {
                $this->tree = $this->tempo->trace($traceId);

                return $this->tree instanceof TraceTree && array_values(array_intersect($expected, $this->tree->services())) === $expected;
            },
            $seconds,
            fn (): string => sprintf('Tempo has no trace %s with spans of %s; it has: %s.', $traceId, implode(', ', $expected), implode(', ', $this->tree?->services() ?? [])),
        );
    }

    #[Then("every span of that trace in Tempo has its parent in the trace or the client's span as parent")]
    public function everySpanHasItsParentInTheTrace(): void
    {
        $tree = $this->tree ?? throw new LogicException('Read the trace from Tempo first.');
        $orphans = $tree->orphans([$this->tracing->clientSpanId()]);

        Assert::same($orphans, [], sprintf('Spans whose parent is not in the trace: %s.', implode(', ', $orphans)));
    }

    #[Then('within :seconds seconds the :service logs contain :text')]
    public function theLogsContain(int $seconds, string $service, string $text): void
    {
        $since = $this->tracing->startedAt();

        Wait::until(
            fn (): bool => $this->loki->linesOfServiceContaining($service, $text, $since) !== [],
            $seconds,
            sprintf('The "%s" logs did not contain "%s" in time.', $service, $text),
        );
    }
}
