<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SloCatalogTest extends KernelTestCase
{
    public function testSlosAreDeclaredWithTheirStrategies(): void
    {
        self::assertSame([
            'billing.process_payment' => [
                'point_type' => 'messenger_message',
                'strategies' => ['availability' => 'existing_series', 'latency' => 'existing_series'],
            ],
        ], $this->declaredSlos());
    }

    public function testCatalogValidatesAndCoverageGatePasses(): void
    {
        $validate = $this->console('slo:validate');
        self::assertSame(0, $validate->getStatusCode(), $validate->getDisplay());

        $coverage = $this->console('slo:coverage');
        self::assertSame(0, $coverage->getStatusCode(), $coverage->getDisplay());
    }

    /**
     * @return array<string, array{point_type: string, strategies: array<string, string>}>
     */
    private function declaredSlos(): array
    {
        $list = $this->console('slo:list', ['--format' => 'json']);
        self::assertSame(0, $list->getStatusCode(), $list->getDisplay());

        $entries = json_decode($list->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($entries);

        $slos = [];
        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['name'] ?? null);
            self::assertIsString($entry['point_type'] ?? null);
            self::assertIsArray($entry['objectives'] ?? null);

            $strategies = [];
            foreach ($entry['objectives'] as $objective) {
                self::assertIsArray($objective);
                self::assertIsString($objective['kind'] ?? null);
                self::assertIsString($objective['strategy'] ?? null);
                $strategies[$objective['kind']] = $objective['strategy'];
            }

            $slos[$entry['name']] = ['point_type' => $entry['point_type'], 'strategies' => $strategies];
        }

        return $slos;
    }

    /**
     * @param array<string, string> $input
     */
    private function console(string $name, array $input = []): CommandTester
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find($name));
        $tester->execute($input);

        return $tester;
    }
}
