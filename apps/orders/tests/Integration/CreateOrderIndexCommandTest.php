<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Elastica\Response;
use FOS\ElasticaBundle\Elastica\Index;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateOrderIndexCommandTest extends KernelTestCase
{
    public function testCreatesAMissingIndexWithTheConfiguredMapping(): void
    {
        $index = $this->index(exists: false);
        $index->expects(self::once())
            ->method('create')
            ->with(self::callback(static function (mixed $mapping): bool {
                self::assertIsArray($mapping);
                self::assertIsArray($mapping['mappings'] ?? null);
                self::assertSame([
                    'id' => ['type' => 'keyword'],
                    'status' => ['type' => 'keyword'],
                    'amount' => ['type' => 'scaled_float', 'scaling_factor' => 100],
                    'createdAt' => ['type' => 'date'],
                ], $mapping['mappings']['properties'] ?? null);

                return true;
            }))
            ->willReturn(new Response('{"acknowledged":true}', 200))
        ;

        $tester = $this->createIndex();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Creating orders', $tester->getDisplay());
    }

    public function testLeavesAnExistingIndexAndItsDocumentsAlone(): void
    {
        $index = $this->index(exists: true);
        $index->expects(self::never())->method('create');

        $tester = $this->createIndex();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Index "orders" already exists.', $tester->getDisplay());
    }

    /**
     * Shared by this command and fos:elastica:create (through FOSElasticaBundle's index manager).
     */
    private function index(bool $exists): Index&MockObject
    {
        self::bootKernel();
        $index = $this->createMock(Index::class);
        $index->method('getName')->willReturn('orders');
        $index->method('exists')->willReturn($exists);
        self::getContainer()->set('fos_elastica.index.orders', $index);

        return $index;
    }

    private function createIndex(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('app:search:create-index'));
        $tester->execute([]);

        return $tester;
    }
}
