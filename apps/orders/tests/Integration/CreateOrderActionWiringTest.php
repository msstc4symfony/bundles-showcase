<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Order\Create\Action;
use App\Controller\CreateOrderController;
use Msstc4Symfony\LogicBundle\Application\Pipeline\ActionPipeline;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CreateOrderActionWiringTest extends KernelTestCase
{
    public function testTheActionServiceIsTheLogicPipeline(): void
    {
        self::assertInstanceOf(ActionPipeline::class, self::getContainer()->get(Action::class));
    }

    public function testTheControllerCallsThePipelineNotTheBareAction(): void
    {
        $controller = self::getContainer()->get(CreateOrderController::class);
        self::assertInstanceOf(CreateOrderController::class, $controller);

        self::assertInstanceOf(ActionPipeline::class, new ReflectionProperty($controller, 'create')->getValue($controller));
    }
}
