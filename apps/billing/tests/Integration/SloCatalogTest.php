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
     * @return array<non-empty-string, array{point_type: non-empty-string, strategies: array<non-empty-string, non-empty-string>}>
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
            self::assertIsArray($entry['objectives'] ?? null);

            $strategies = [];
            foreach ($entry['objectives'] as $objective) {
                self::assertIsArray($objective);
                $strategies[$this->nonEmptyString($objective['kind'] ?? null)] = $this->nonEmptyString($objective['strategy'] ?? null);
            }

            $slos[$this->nonEmptyString($entry['name'] ?? null)] = [
                'point_type' => $this->nonEmptyString($entry['point_type'] ?? null),
                'strategies' => $strategies,
            ];
        }

        return $slos;
    }

    /**
     * @return non-empty-string
     */
    private function nonEmptyString(mixed $value): string
    {
        self::assertIsString($value);
        if ($value === '') {
            self::fail('Expected a non-empty string in the slo:list JSON');
        }

        return $value;
    }

    /**
     * @param non-empty-string $name
     * @param array<string, string> $input
     */
    private function console(string $name, array $input = []): CommandTester
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find($name));
        $tester->execute($input);

        return $tester;
    }
}
