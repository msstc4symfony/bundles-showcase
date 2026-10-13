<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Order\Create\Action;
use Monolog\Handler\TestHandler;
use Prometheus\RegistryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LogicBridgesTest extends WebTestCase
{
    private const string ACTION_METRIC = 'symfony_logic_action_duration_histogram_seconds';

    private const string TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    private const string CALLER_SPAN_ID = '00f067aa0ba902b7';

    // logic-bundle's LoggingMiddleware writes "Start|Finish|Fail application action <name>" inside the Action's span.
    private const string ACTION_LOG_MARKER = 'application action';

    public function testCreatingAnOrderRecordsASuccessfulActionRun(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/orders', ['amount' => 5000]);
        self::assertResponseStatusCodeSame(201);

        self::assertSame(['success' => 1], $this->actionRunsByOutcome());
    }

    public function testARejectedPayloadNeverReachesTheAction(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/orders', ['amount' => 0]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame([], $this->actionRunsByOutcome());
    }

    public function testTheActionLogsInItsOwnSpanUnderTheRequestsSpan(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/orders', ['amount' => 5000], [
            'HTTP_TRACEPARENT' => '00-' . self::TRACE_ID . '-' . self::CALLER_SPAN_ID . '-01',
        ]);
        self::assertResponseStatusCodeSame(201);

        $handler = self::getContainer()->get('monolog.handler.main');
        self::assertInstanceOf(TestHandler::class, $handler);
        $parents = [];
        $actionSpans = [];
        foreach ($handler->getRecords() as $record) {
            self::assertSame(self::TRACE_ID, $record->extra['trace_id'] ?? null, $record->message);
            $span = $record->extra['span_id'] ?? null;
            self::assertIsString($span);
            $parent = $record->extra['parent_span_id'] ?? null;
            if (array_key_exists($span, $parents)) {
                self::assertSame($parents[$span], $parent, $record->message);
            }

            $parents[$span] = $parent;
            if (str_contains($record->message, self::ACTION_LOG_MARKER)) {
                $actionSpans[$span] = true;
            }
        }

        self::assertCount(1, $actionSpans);
        $actionSpan = (string) array_key_first($actionSpans);
        $requestSpan = $parents[$actionSpan] ?? null;
        self::assertIsString($requestSpan);
        $expected = [$requestSpan => self::CALLER_SPAN_ID, $actionSpan => $requestSpan];
        ksort($expected);
        ksort($parents);
        self::assertSame($expected, $parents);
    }

    /**
     * @return array<string, positive-int>
     */
    private function actionRunsByOutcome(): array
    {
        $registry = self::getContainer()->get(RegistryInterface::class);
        self::assertInstanceOf(RegistryInterface::class, $registry);
        $runs = [];
        foreach ($registry->getMetricFamilySamples() as $family) {
            if ($family->getName() !== self::ACTION_METRIC) {
                continue;
            }

            foreach ($family->getSamples() as $sample) {
                if ($sample->getName() !== self::ACTION_METRIC . '_count') {
                    continue;
                }

                $labels = array_combine($family->getLabelNames(), $sample->getLabelValues());
                $outcome = $labels['outcome'] ?? null;
                $count = (int) $sample->getValue();
                if (($labels['action'] ?? null) === Action::class && is_string($outcome) && $count > 0) {
                    $runs[$outcome] = $count;
                }
            }
        }

        return $runs;
    }
}
