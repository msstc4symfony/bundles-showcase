<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Order\Create\Action;
use Prometheus\RegistryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LogicBridgesTest extends WebTestCase
{
    private const string ACTION_METRIC = 'symfony_logic_action_duration_histogram_seconds';

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
