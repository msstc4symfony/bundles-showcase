<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Msstc4Symfony\ProfilingBundle\Application\ProfilingFactoryInterface;
use Msstc4Symfony\TracingBundle\Application\Port\TraceContextHolderInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KernelBootTest extends KernelTestCase
{
    public function testAllShowcaseBundlesAreWired(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertTrue($container->has(ProfilingFactoryInterface::class));
        self::assertTrue($container->has(TraceContextHolderInterface::class));
        self::assertSame('showcase', $container->getParameter('msstc4symfony_metrics.application_name'));
    }
}
